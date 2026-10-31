<?php

declare(strict_types=1);

namespace App\Modules\Billing\Documents;

use App\Modules\Billing\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Die Dokumente einer Rechnung: HTML zum Drucken, PDF zum Archivieren und
 * Versenden, XRechnung zum Einreichen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIN BLATT, ZWEI AUSGABEN. Dieselbe Blade-Vorlage rendert den Bildschirm
 * (mit Druckknopf) und das PDF (ohne). Dompdf kann kein Flexbox und kein
 * Grid -- die Vorlage ist deshalb mit Tabellen gesetzt, so wie Briefe seit
 * jeher gesetzt werden. DejaVu Sans ist die Schrift, die Dompdf mitbringt
 * und die ein Eurozeichen kennt.
 *
 * ARCHIVIERT WIRD BEIM STELLEN, idempotent: ensureArchived() legt an, was
 * fehlt, und lässt stehen, was da ist. Das archivierte PDF ist der Beleg;
 * ein späterer Aufruf erzeugt keinen zweiten. Die Rechnung ist eingefroren,
 * ein Neu-Rendern ergäbe dasselbe Blatt -- aber der Beleg ist die Datei, die
 * verschickt wurde, nicht die Behauptung, dass es dieselbe wäre.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class InvoiceDocuments
{
    public const PDF = 'pdf';

    public const XML = 'xrechnung';

    public function __construct(private XRechnungWriter $xrechnung) {}

    public function html(Invoice $invoice, bool $forPdf = false): string
    {
        $invoice->loadMissing(['lines', 'corrects', 'correctedBy']);

        return View::make('billing.print.invoice', [
            'invoice' => $invoice,
            'organisation' => (array) config('aeronance.organisation', []),
            'footer' => (string) config('aeronance.billing.footer', ''),
            'forPdf' => $forPdf,
        ])->render();
    }

    public function pdf(Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', resource_path('views/billing'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($invoice, forPdf: true), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $pdf = $dompdf->output();

        if ($pdf === null || $pdf === '') {
            throw new RuntimeException('Dompdf produced no output for invoice '.$invoice->label());
        }

        return $pdf;
    }

    public function xml(Invoice $invoice): string
    {
        return $this->xrechnung->write($invoice);
    }

    /** Legt PDF und XRechnung an, soweit sie fehlen. Nur für gestellte Rechnungen. */
    public function ensureArchived(Invoice $invoice): void
    {
        if (! $invoice->isFrozen() || $invoice->number === null) {
            throw new RuntimeException('Only an issued invoice is archived; a draft has no number and no document.');
        }

        if ($this->media($invoice, self::PDF) === null) {
            $invoice->addMediaFromString($this->pdf($invoice))
                ->usingFileName($invoice->number.'.pdf')
                ->withCustomProperties(['type' => self::PDF])
                ->toMediaCollection(Invoice::DOCUMENTS);
        }

        if ($this->media($invoice, self::XML) === null) {
            $invoice->addMediaFromString($this->xml($invoice))
                ->usingFileName($invoice->number.'.xml')
                ->withCustomProperties(['type' => self::XML])
                ->toMediaCollection(Invoice::DOCUMENTS);
        }
    }

    public function media(Invoice $invoice, string $type): ?Media
    {
        return $invoice->getMedia(Invoice::DOCUMENTS)
            ->first(static fn (Media $media): bool => $media->getCustomProperty('type') === $type);
    }
}
