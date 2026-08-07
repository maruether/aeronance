<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Core\Documents\DocumentType;
use App\Core\Modules\ModuleManager;
use App\Modules\Warehouse\Models\StockLot;
use App\Modules\Warehouse\Permissions;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out a certificate file.
 *
 * The only way to one. The files sit on a private disk outside the web root, so
 * there is no address to guess -- and this route checks three things before
 * streaming anything:
 *
 *  1. the module is active at all;
 *  2. the person may see stock;
 *  3. the requested file actually belongs to the lot in the URL.
 *
 * The third is the one worth spelling out. Without it, a valid lot id plus
 * somebody else's media id would fetch a document the caller has no business
 * seeing -- the classic broken-object-reference, and the reason the check is on
 * the relationship rather than on the file alone.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The headers matter as much as the checks, and for a reason that is easy to
 * miss: this route serves attacker-supplied bytes from the application's OWN
 * origin. If a browser can be persuaded to treat those bytes as HTML, any script
 * in them runs with the session of whoever opened it. That is stored XSS with
 * the full run of the panel.
 *
 * Three things prevent it, and none of them relies on the file being what it
 * claims:
 *
 *  - The Content-Type is taken from the FILE'S OWN first bytes, read here, not
 *    from the mime_type column. A column can be wrong; the bytes are the file.
 *  - X-Content-Type-Options: nosniff removes the browser's discretion. Without
 *    it, Internet Explorer's descendants will still helpfully decide that
 *    something served as application/pdf looks rather like HTML.
 *  - A Content-Security-Policy of default-src 'none' plus sandbox means that
 *    even if both of the above failed, there is nothing the document may load
 *    and no origin it may act in.
 *
 * Anything not recognisable is sent as an attachment with a neutral type, so it
 * is downloaded rather than rendered.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DocumentController
{
    public function __invoke(Request $request, StockLot $lot, Media $media): StreamedResponse
    {
        abort_unless(app(ModuleManager::class)->isEnabled('warehouse'), 404);

        abort_unless($request->user()?->can(Permissions::STOCK_VIEW) ?? false, 403);

        // The file has to belong to THIS lot, not merely exist.
        abort_unless(
            $media->model_type === StockLot::class
            && (int) $media->model_id === (int) $lot->getKey()
            && $media->collection_name === StockLot::DOCUMENTS,
            404,
        );

        $type = $this->typeOnDisk($media);

        $response = $media->toInlineResponse($request);

        // Read from the bytes, not from the record. Storage is not a threat
        // model on its own, but a Content-Type that comes from a column is a
        // Content-Type that can be wrong, and this one decides how a browser
        // treats the response.
        $response->headers->set(
            'Content-Type',
            $type?->value ?? 'application/octet-stream',
        );

        $response->headers->set(
            'Content-Disposition',
            sprintf(
                '%s; filename="%s"',
                $type === null ? 'attachment' : 'inline',
                $this->safeFilename($media, $type),
            ),
        );

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; object-src 'none'; sandbox");
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * What the file on disk actually is, by its opening bytes.
     */
    private function typeOnDisk(Media $media): ?DocumentType
    {
        $stream = $media->stream();

        try {
            $head = (string) fread($stream, 16);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return DocumentType::fromContent($head);
    }

    /**
     * A file name the header can carry safely.
     *
     * Quotes and newlines in a Content-Disposition are how one header becomes
     * two. The name is cosmetic here -- the file is identified by its id -- so
     * anything doubtful is simply dropped.
     */
    private function safeFilename(Media $media, ?DocumentType $type): string
    {
        $base = pathinfo((string) $media->file_name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? '';
        $base = trim(substr($base, 0, 80), '._-');

        if ($base === '') {
            $base = 'dokument-'.$media->getKey();
        }

        return $base.'.'.($type?->extensions()[0] ?? 'bin');
    }
}
