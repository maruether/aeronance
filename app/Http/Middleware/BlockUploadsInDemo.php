<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Demo\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Tür hinter dem abgeschalteten Uploadfeld.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Die Felder sind im Demomodus deaktiviert (siehe DemoServiceProvider) -- aber
 * ein deaktiviertes Feld ist eine Aussage der Oberfläche, und „Rechte, die nur
 * im UI versteckt sind, gelten als nicht vorhanden". Der Upload-Endpunkt von
 * Livewire ist ohne dieses Stück auch dann erreichbar, wenn kein einziges
 * Formular ihn anbietet: ein POST genügt.
 *
 * Auf einer öffentlich erreichbaren Instanz ist das der Unterschied zwischen
 * „hier kann man nichts hochladen" und „hier lädt niemand etwas hoch, der die
 * Maske benutzt". Genau deshalb greift die Sperre an der Route und nicht im
 * Formular.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BlockUploadsInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isUpload($request) && app(DemoMode::class)->isActive()) {
            abort(403, __('demo.upload_refused'));
        }

        return $next($request);
    }

    /**
     * Livewires Upload-Endpunkte, nach Pfad und nicht nach Routennamen.
     *
     * Der Name (`livewire.upload-file`) ist ein Detail des Pakets; der Pfad ist
     * seit Jahren derselbe und steht in jeder Fassung. Eine Sperre, die nach
     * einem Paket-Update stillschweigend nicht mehr greift, wäre schlimmer als
     * keine.
     */
    private function isUpload(Request $request): bool
    {
        return $request->isMethod('POST')
            && ($request->is('livewire/upload-file') || $request->is('livewire/*/upload-file'));
    }
}
