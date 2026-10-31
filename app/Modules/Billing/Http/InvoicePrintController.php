<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http;

use App\Core\Modules\ModuleManager;
use App\Modules\Billing\Documents\InvoiceDocuments;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Die Rechnung als Blatt im Browser -- auch für Entwürfe, zur Vorschau.
 */
final readonly class InvoicePrintController
{
    public function __construct(private InvoiceDocuments $documents) {}

    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless(app(ModuleManager::class)->isEnabled('billing'), 404);
        abort_unless($request->user()?->can(Permissions::VIEW) ?? false, 403);

        return new Response($this->documents->html($invoice), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
