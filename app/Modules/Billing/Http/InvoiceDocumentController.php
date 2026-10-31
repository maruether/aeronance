<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Billing\Documents\InvoiceDocuments;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF und XRechnung einer gestellten Rechnung -- aus dem Archiv, nicht neu
 * gerendert. Das archivierte Blatt ist der Beleg.
 *
 * Die Kopfzeilen sind dieselben wie beim Dokumentenausgang des Lagers: Der
 * Browser bekommt gesagt, was es ist, und darf nichts daraus ausführen.
 */
final readonly class InvoiceDocumentController
{
    public function __construct(private InvoiceDocuments $documents) {}

    public function pdf(Request $request, Invoice $invoice): StreamedResponse
    {
        return $this->serve($request, $invoice, InvoiceDocuments::PDF, 'application/pdf', 'inline');
    }

    public function xml(Request $request, Invoice $invoice): StreamedResponse
    {
        return $this->serve($request, $invoice, InvoiceDocuments::XML, 'application/xml', 'attachment');
    }

    private function serve(Request $request, Invoice $invoice, string $type, string $mime, string $disposition): StreamedResponse
    {
        abort_unless(app(ModuleManager::class)->isEnabled('billing'), 404);
        abort_unless($request->user()?->can(Permissions::VIEW) ?? false, 403);
        abort_unless($invoice->isFrozen(), 404);

        $this->documents->ensureArchived($invoice);
        $media = $this->documents->media($invoice, $type);
        abort_if($media === null, 404);

        $response = $media->toInlineResponse($request);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', sprintf('%s; filename="%s"', $disposition, $media->file_name));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; object-src 'none'; sandbox");
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
