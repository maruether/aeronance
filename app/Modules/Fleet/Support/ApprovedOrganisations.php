<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Support;

use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * Der Blick ins Betriebsverzeichnis — über die Modulgrenze hinweg.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS VERZEICHNIS GEHÖRT DEM LAGER, die Frage stellt die Flotte.
 *
 * Der Betrieb, der ein Luftfahrzeug instand setzt, und die Firma, von der man
 * Teile kauft, sind dieselbe Firma. Trotzdem darf die Flotte nicht einfach in
 * die Lagertabellen greifen: Sie steht allein, und ein Verein ohne Lagermodul
 * muss Fremdvergabe genauso führen können.
 *
 * Deshalb diese Kapsel. Sie fragt zuerst, ob das Lager überhaupt da ist, und
 * fasst die fremde Klasse erst danach an — genau die Bauweise, die
 * ModuleBoundaryTest für optionale Abhängigkeiten vorsieht (`fleet` →
 * `warehouse` ist dort bereits erklärt).
 *
 * Ohne Lagermodul ist die Antwort schlicht „kein Verzeichnis", und die
 * Fremdvergabe läuft wie bisher über Freitext weiter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ApprovedOrganisations
{
    public static function available(): bool
    {
        return app(ModuleManager::class)->isEnabled('warehouse');
    }

    /**
     * Die eintragbaren Betriebe, für eine Auswahlliste.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        if (! self::available()) {
            return [];
        }

        return Supplier::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($betrieb): array => [
                $betrieb->id => $betrieb->labelWithApproval().($betrieb->approvalHasLapsed()
                    ? ' — '.__('fleet.external.approval_lapsed_short')
                    : ''),
            ])
            ->all();
    }

    /**
     * Name, Zulassungsnummer und Ablauf eines Betriebs.
     *
     * Gibt `null` zurück, wenn es das Lager nicht gibt oder die Kennung nicht
     * passt — die aufrufende Aktion behandelt beides gleich und bleibt beim
     * Freitext.
     *
     * @return array{name: string, approval: ?string, lapsed: bool, expires_at: ?Carbon}|null
     */
    public static function find(?int $id): ?array
    {
        if ($id === null || ! self::available()) {
            return null;
        }

        $betrieb = Supplier::find($id);

        if ($betrieb === null) {
            return null;
        }

        return [
            'name' => $betrieb->name,
            'approval' => $betrieb->approval_number,
            'lapsed' => $betrieb->approvalHasLapsed(),
            'expires_at' => $betrieb->approval_expires_at,
        ];
    }
}
