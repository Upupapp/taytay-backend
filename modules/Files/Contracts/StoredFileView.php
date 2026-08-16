<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

/**
 * What another module may know about a stored file.
 *
 * NOT THE ELOQUENT MODEL, and that is the whole point. `disk` and `storage_key` are absent, so a
 * consuming module cannot learn where the bytes live, cannot build a path, and cannot be tempted
 * to read one — which is what makes "direct object-storage paths are not public" a property of
 * the code rather than a habit (ADR 0020 §5).
 *
 * `contentHash` and `uploadedBy` are absent too: nobody outside Files needs them, and the
 * smallest published surface is the one least likely to grow a dependency somebody later has to
 * unpick.
 */
final class StoredFileView
{
    public function __construct(
        public readonly string $id,
        /** Sanitised, with the extension corrected to the verified type. Safe to display. */
        public readonly string $name,
        /** The VERIFIED type, read from the file's own bytes. Never what the caller declared. */
        public readonly string $mimeType,
        public readonly int $byteSize,
        public readonly ?int $pageCount,
        public readonly ScanStatus $scanStatus,
        /** False once purged under retention, or while quarantined. */
        public readonly bool $isAvailable,
    ) {}
}
