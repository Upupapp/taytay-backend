<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\StoredFileView;
use Modules\Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exchanging a handle for bytes (ADR 0020 §5).
 *
 * THE ONE PLACE A DOCUMENT LEAVES THIS SYSTEM. Two acceptance criteria are held here and by the
 * disk configuration together:
 *
 *  * **Direct object-storage paths are not public** — the `object-storage` disk is configured
 *    without a `url`, so no code path can produce a public link even by accident, and the bytes
 *    are streamed by this application after a decision.
 *  * **A guessed file id cannot be downloaded** — there is no file id in this contract at all.
 *    The only thing that opens a document is a grant issued to *this account* moments ago, for
 *    *this version*, already checked against the case's barangay scope by the controller that
 *    issued it.
 *
 * Authorization happened when the grant was issued and is re-checked on redemption: the holder
 * must match, the clock must not have run out, and it must not already have been used. A handle
 * that leaks — a browser history, a pasted chat line — is useless to whoever finds it.
 *
 * The response is deliberately hostile to being embedded or cached: no store, no sniffing, an
 * attachment disposition, and a frame denial. A citizen's identity document rendered inline in
 * somebody's page is a disclosure with no record of having happened.
 */
final class DocumentDownloadController
{
    public function __construct(private readonly DocumentLibrary $library) {}

    public function __invoke(ActorContext $actor, string $handle): Response
    {
        // Unknown, expired, spent and issued-to-somebody-else all raise NOT FOUND from inside.
        // Distinguishing them would confirm which handles were once real.
        $redeemed = $this->library->redeem($handle, $actor);

        /** @var StoredFileView $file */
        $file = $redeemed['file'];
        $contents = (string) $redeemed['contents'];

        $response = new StreamedResponse(function () use ($contents): void {
            echo $contents;
        });

        // The VERIFIED type from storage, never anything the uploader declared. Serving a file
        // as a type it is not is how a stored image becomes an executed script in a browser.
        $response->headers->set('Content-Type', $file->mimeType);
        $response->headers->set('Content-Length', (string) $file->byteSize);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="'.addslashes($file->name).'"',
        );

        // No intermediary keeps a copy of somebody's identity document.
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }
}
