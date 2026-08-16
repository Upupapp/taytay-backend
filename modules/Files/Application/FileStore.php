<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\ScanStatus;
use Modules\Files\Domain\AcceptedMediaType;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Files\Jobs\ProcessUploadedFile;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Accepting bytes, safely (ADR 0020 §2).
 *
 * THE ONLY WAY INTO THE OBJECT STORE. Every upload in this system arrives here, so the checks
 * below are the checks, and no caller can accidentally skip them by writing to the disk itself.
 *
 * Four things are decided here and taken from nobody:
 *
 *  1. **The type**, read from the file's own leading bytes. The declared `Content-Type` and the
 *     extension both come from the caller and both look correct on a file that is neither.
 *  2. **The size**, checked against a constant that must stay below the reverse proxy's limit —
 *     see {@see AcceptedMediaType::MAX_BYTES} for why the proxy answering first is worse than a
 *     rejection.
 *  3. **The storage key**, generated from a UUID and the verified type's extension. Nothing the
 *     caller sent contributes a character. A caller-supplied filename is caller-supplied path
 *     input, and `../` in a filename is the oldest write-anywhere bug there is.
 *  4. **The disk**, always private. There is no code path here that writes to `public`.
 */
final class FileStore
{
    public function __construct(private readonly FilesAudit $audit) {}

    /**
     * Validates an upload and stores it privately.
     *
     * @throws ApiException when the file is too large, unreadable, or not a type this office
     *                      accepts. Each is a distinct error code, because the caller's recovery
     *                      differs: shrink it, re-pick it, or give up on this file entirely.
     */
    public function store(
        UploadedFile $upload,
        FileClassification $classification,
        ActorContext $actor,
    ): StoredFile {
        if (! $upload->isValid()) {
            // A PHP-level upload failure: truncated body, exceeded `upload_max_filesize`, no
            // temp directory. Reported as too-large because that is overwhelmingly what it is,
            // and because the alternative is telling somebody "something went wrong".
            throw new ApiException(ErrorCode::PayloadTooLarge, 'That file could not be received. It may be too large.');
        }

        $size = (int) $upload->getSize();

        if ($size === 0) {
            throw new ApiException(ErrorCode::ValidationFailed, 'That file is empty.');
        }

        if ($size > AcceptedMediaType::MAX_BYTES) {
            throw new ApiException(ErrorCode::PayloadTooLarge, 'That file is larger than this endpoint accepts.');
        }

        $contents = (string) file_get_contents($upload->getRealPath());

        /*
         * THE TYPE IS READ, NOT ASKED FOR.
         *
         * `$upload->getMimeType()` guesses from the extension in some configurations and from
         * the content in others, and which one you get depends on whether fileinfo is loaded —
         * a security control that varies by deployment is not a control.
         */
        $type = AcceptedMediaType::detect($contents);

        if ($type === null) {
            throw new ApiException(
                ErrorCode::UnsupportedMediaType,
                'That file could not be read as a photo or a PDF.',
            );
        }

        // The extension comes from the verified type; a `.jpg` holding a PDF is stored as a PDF,
        // and a `.php` holding a JPEG is stored as a JPEG. Neither can be served as anything but
        // its real type, and neither reaches the disk under a name the caller chose.
        $key = 'documents/'.now()->format('Y/m').'/'.Str::uuid7().'.'.$type->extension();
        $disk = $this->disk();

        Storage::disk($disk)->put($key, $contents, 'private');

        $file = StoredFile::query()->create([
            'disk' => $disk,
            'storage_key' => $key,
            // Kept to show back to the uploader so they can tell two picks apart. Sanitised
            // because it is displayed, and never used to build a path or choose a type.
            'original_name' => $this->safeDisplayName($upload->getClientOriginalName(), $type),
            'mime_type' => $type->value,
            'byte_size' => $size,
            'content_hash' => hash('sha256', $contents),
            'classification' => $classification,
            'scan_status' => ScanStatus::Pending,
            'uploaded_by' => $actor->subjectId,
        ]);

        // Scanning and metadata extraction are queued: they are slow, they are side-effect work,
        // and a resident on a weak connection should not wait for either (Article: queue slow
        // work, keep request paths predictable).
        ProcessUploadedFile::dispatch((string) $file->uuid)->afterCommit();

        $this->audit->recordFile($actor->subjectId, 'file.uploaded', 'File uploaded', (string) $file->uuid);

        return $file;
    }

    /**
     * The bytes, for a caller that has already been authorised by the owning module.
     */
    public function contents(StoredFile $file): string
    {
        if (! $file->isAvailable()) {
            throw new ApiException(ErrorCode::Conflict, 'That file is no longer available.');
        }

        $contents = Storage::disk((string) $file->disk)->get((string) $file->storage_key);

        if ($contents === null) {
            throw new ApiException(ErrorCode::NotFound, 'That file could not be retrieved.');
        }

        return $contents;
    }

    /**
     * Removes the bytes under retention while keeping the record that a file existed.
     *
     * Deleting the row instead would erase the fact that the applicant ever complied, which is
     * the opposite of what a retention rule is for.
     */
    public function purge(StoredFile $file): void
    {
        if ($file->purged_at !== null) {
            return;
        }

        Storage::disk((string) $file->disk)->delete((string) $file->storage_key);

        $file->forceFill(['purged_at' => now()])->save();
    }

    /**
     * Local development writes to the local disk; everything else to private object storage.
     *
     * Read from configuration rather than branched on the environment name, so a staging box
     * cannot silently behave like a laptop.
     */
    private function disk(): string
    {
        return (string) config('files.disk', 'object-storage');
    }

    /**
     * A display name that is safe to put in a JSON payload and a `Content-Disposition` header.
     *
     * Strips directory separators and control characters, and forces the extension to match the
     * verified type — so a file named `invoice.pdf` that is really a JPEG is shown as a JPEG,
     * rather than telling a reader it is something it is not.
     */
    private function safeDisplayName(string $original, AcceptedMediaType $type): string
    {
        $base = pathinfo(str_replace(['/', '\\', "\0"], '', $original), PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $base) ?? '';
        $base = trim(substr($base, 0, 96));

        return ($base === '' ? 'document' : $base).'.'.$type->extension();
    }
}
