<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Actions;

use App\Core\Access\Authority;
use App\Core\Documents\DocumentIntake;
use App\Core\Documents\Exceptions\DocumentRejected;
use App\Core\Http\HttpFetcher;
use App\Models\User;
use App\Modules\Fleet\Models\AircraftType;
use App\Modules\Fleet\Permissions;
use App\Modules\Fleet\TypeCertificates\CertificateSubject;
use App\Modules\Fleet\TypeCertificates\TypeCertificateCandidate;
use App\Modules\Fleet\TypeCertificates\TypeCertificateRegistry;
use RuntimeException;

/**
 * Taking a search hit and making it this type's certificate.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DOWNLOAD GOES THROUGH THE CORE'S DOCUMENT INTAKE, and that is not
 * ceremony. This is the first place in the project that writes a file fetched
 * from a URL nobody in the club chose -- an authority's server today, a
 * redirect somewhere else the day they reorganise. The intake already checks
 * size, verifies the type from the actual bytes rather than the extension, and
 * runs the virus scanner. Bypassing it here would undo that work for the one
 * case it was built for.
 *
 * Storing the file is OPTIONAL. the requirement was for the data sheet to be linkable;
 * a link costs nothing and never goes stale in the wrong direction, while a
 * stored copy is what a club wants when the authority reorganises its site. Both
 * are offered, and a failed download leaves the link intact rather than the whole
 * act failing.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class AdoptTypeCertificate
{
    public function __construct(
        private Authority $authority,
        private TypeCertificateRegistry $registry,
        private HttpFetcher $fetcher,
    ) {}

    /**
     * @return list<TypeCertificateCandidate>
     */
    public function search(
        string $designation,
        CertificateSubject $subject = CertificateSubject::Aircraft,
    ): array {
        return $this->registry->searchAll($designation, $subject);
    }

    /**
     * The search, plus any authority that could not be asked.
     *
     * @return array{candidates: list<TypeCertificateCandidate>, problems: array<string, string>}
     */
    public function searchWithProblems(
        string $designation,
        CertificateSubject $subject = CertificateSubject::Aircraft,
    ): array {
        return $this->registry->searchWithProblems($designation, $subject);
    }

    /**
     * Write a candidate onto a type, optionally fetching the document.
     *
     * @return array{type: AircraftType, stored: bool, problem: ?string}
     */
    public function adopt(
        AircraftType $type,
        TypeCertificateCandidate $candidate,
        User $user,
        bool $storeDocument = true,
    ): array {
        if (! $this->authority->permits($user, Permissions::FLEET_MANAGE)) {
            throw new RuntimeException(sprintf(
                'Recording a type certificate requires the "%s" permission.',
                Permissions::FLEET_MANAGE,
            ));
        }

        // Resolved now, not during the search: the details cost one request per
        // candidate, and only the chosen one is worth it.
        $resolved = $this->resolve($candidate);

        /*
         * ─────────────────────────────────────────────────────────────────────
         * WHICH NUMBER LEADS, AND IT IS NOT THE ONE THAT WAS SEARCHED FOR.
         *
         * Vorgabe: "ein lfz das ursprünglich nach nationalem recht zugelassen
         * wurde und später eine änderung erhalten hat, hat initial ein LBA
         * Kennblatt erhalten und danach ein EASA TCDS. Wenn beides oder nur ein
         * TCDS angegeben sind, zählt immer das TCDS. Nur wenn keines vorhanden
         * ist zählt das alte LBA Kennblatt."
         *
         * So the two numbers in a Blaues-Buch row are not equals. The EASA TCDS
         * supersedes the Kennblatt; the Kennblatt is the answer only where no
         * TCDS was ever issued. Storing whichever number the search happened to
         * run against would have made the leading number depend on which
         * catalogue somebody opened.
         *
         * The old Kennblatt is kept all the same -- as a secondary number, not
         * on display. It costs one row and it is what lets a publication that
         * still quotes "339/SP" find the type at all. Vorgabe: "falls zur
         * zuordnung nötig kann das alte kennblatt irgendwo mitgeführt werden."
         * ─────────────────────────────────────────────────────────────────────
         */
        $leading = $this->leading($resolved);

        $type->fill([
            'type_certificate' => $leading['number'],
            'certificate_authority' => $leading['authority'],
            'data_sheet_url' => $resolved->dataSheetUrl ?? $resolved->pageUrl,
            'data_sheet_checked_at' => now()->toDateString(),
            'source' => $resolved->authority,
        ]);

        if (filled($resolved->manufacturer) && blank($type->manufacturer)) {
            $type->manufacturer = $resolved->manufacturer;
        }

        $type->save();

        /*
         * Every number the authority named, so a directive quoting any of them
         * reaches this type. The leading one is written by the model's own
         * mirror on save -- see AircraftType::booted().
         */
        foreach ($this->numbersOf($resolved) as $number) {
            if ($number['number'] !== $leading['number']) {
                $type->recordCertificate($number['number'], $number['authority']);
            }
        }

        if (! $storeDocument || $resolved->dataSheetUrl === null) {
            return ['type' => $type->fresh(), 'stored' => false, 'problem' => null];
        }

        try {
            $this->storeDataSheet($type, $resolved);
        } catch (DocumentRejected|RuntimeException $e) {
            /*
             * A failed download is reported, not thrown. The number and the link
             * are already recorded and useful on their own -- losing them because
             * a PDF could not be fetched would be the wrong trade.
             */
            return ['type' => $type->fresh(), 'stored' => false, 'problem' => $e->getMessage()];
        }

        return ['type' => $type->fresh(), 'stored' => true, 'problem' => null];
    }

    private function resolve(TypeCertificateCandidate $candidate): TypeCertificateCandidate
    {
        $source = $this->registry->has($candidate->authority)
            ? $this->registry->get($candidate->authority)
            : null;

        return $source !== null && method_exists($source, 'resolve')
            ? $source->resolve($candidate)
            : $candidate;
    }

    private function storeDataSheet(AircraftType $type, TypeCertificateCandidate $candidate): void
    {
        $bytes = $this->fetcher->get((string) $candidate->dataSheetUrl);

        if ($bytes === '') {
            throw new RuntimeException('The authority returned an empty document.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'tcds');

        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary file for the download.');
        }

        try {
            file_put_contents($temp, $bytes);

            // Size, real content type, virus scan -- the core's rules, applied to
            // a file that came from outside the club.
            app(DocumentIntake::class)->accept($temp, $this->filename($candidate));

            $type->clearMediaCollection(AircraftType::DATA_SHEET);

            $type->addMedia($temp)
                ->usingFileName($this->filename($candidate))
                ->toMediaCollection(AircraftType::DATA_SHEET);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function filename(TypeCertificateCandidate $candidate): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '-', $candidate->certificate) ?? 'kennblatt';

        return trim($base, '-').'.pdf';
    }

    /**
     * Every number this candidate names, the one searched for included.
     *
     * @return list<array{number: string, authority: string}>
     */
    private function numbersOf(TypeCertificateCandidate $candidate): array
    {
        $numbers = [[
            'number' => trim($candidate->certificate),
            'authority' => $candidate->authority,
        ]];

        foreach ($candidate->alsoFiledAs as $other) {
            $number = trim((string) ($other['number'] ?? ''));

            if ($number !== '') {
                $numbers[] = [
                    'number' => $number,
                    'authority' => (string) ($other['authority'] ?? AircraftType::AUTHORITY_OTHER),
                ];
            }
        }

        return $numbers;
    }

    /**
     * The number that counts: the EASA TCDS where one exists.
     *
     * See adopt() for why -- a national Kennblatt is superseded by the TCDS the
     * moment one is issued, and only stands on its own where none ever was.
     *
     * @return array{number: string, authority: string}
     */
    private function leading(TypeCertificateCandidate $candidate): array
    {
        $numbers = $this->numbersOf($candidate);

        foreach ($numbers as $number) {
            if ($number['authority'] === AircraftType::AUTHORITY_EASA) {
                return $number;
            }
        }

        return $numbers[0];
    }
}
