<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\ScanStatus;
use Modules\Files\Domain\ImageDerivative;
use Modules\Files\Domain\MediaVariant;
use Modules\Files\Domain\MediaVisibility;
use Modules\Files\Infrastructure\Eloquent\MediaVariantRecord;
use Modules\Files\Infrastructure\Eloquent\StoredFile;

/**
 * The only way an object becomes publicly reachable (ADR 0033 §3).
 *
 * **THE ORIGINAL NEVER MOVES.** Publication does not copy, promote, relocate or re-permission the
 * uploaded file. It derives a *new* object by re-encoding the image from decoded pixels and writes
 * that to the public bucket. The uploaded bytes stay on the private disk for their whole life, so
 * the master command's "do not place sensitive attachments in a public bucket even temporarily" is
 * not a rule anybody has to follow — there is no code path that could.
 *
 * FOUR GATES, ALL OF WHICH MUST PASS:
 *
 *  1. **the classification must be publishable.** Personal and sensitive material is refused
 *     outright, whatever the calling module believes. A newsfeed post that somehow attached a KYC
 *     photograph cannot publish it, because the refusal lives here rather than in the caller;
 *  2. **the scan must not have failed.** An infected file is never republished as anything;
 *  3. **it must be an image.** There is no public PDF path. A PDF cannot be re-encoded through a
 *     pixel buffer, so its metadata would have to be *stripped* — and a stripper that misses a
 *     field leaks silently (see `ImageDerivative`);
 *  4. **the derivation must succeed.** If GD is unavailable or the bytes will not decode, no
 *     public object is produced. Falling back to copying the original would put an EXIF-carrying
 *     file in a public bucket on exactly the one host where the extension was missing.
 *
 * Withdrawal deletes the objects and the rows. A post taken down whose image stayed at a public
 * URL would be a takedown that did not take anything down.
 */
final class MediaPublisher
{
    public function __construct(private readonly FilesAudit $audit) {}

    /**
     * Derives and publishes the public renditions of an image.
     *
     * IDEMPOTENT. Publishing twice re-derives nothing: the existing rows are returned. A post
     * edited and republished, or a job retried, must not accumulate objects.
     *
     * @return list<MediaVariantRecord>
     */
    public function publish(StoredFile $file): array
    {
        if (! $this->mayBePublished($file)) {
            return [];
        }

        $existing = MediaVariantRecord::query()
            ->where('stored_file_id', $file->id)
            ->where('visibility', MediaVisibility::Public->value)
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $source = Storage::disk((string) $file->disk)->get((string) $file->storage_key);

        if ($source === null) {
            return [];
        }

        $published = [];

        foreach (MediaVariant::all() as $variant) {
            $rendition = ImageDerivative::render($source, $variant->maxEdge());

            /*
             * NO FALLBACK TO THE ORIGINAL. If the derivation fails there is simply no public
             * object for this variant — the content publishes without an image rather than with
             * an unprocessed one. A silent fallback would be a metadata leak that appears only
             * where the image library is missing, which is the hardest kind to ever notice.
             */
            if ($rendition === null) {
                continue;
            }

            [$bytes, $width, $height] = $rendition;

            $disk = MediaVisibility::Public->disk();

            /*
             * An opaque key, like every other object here. Nothing the uploader named contributes
             * a character — and on a PUBLIC bucket that matters twice over, because a guessable
             * key is a directory listing for anybody who wants one.
             */
            $key = 'media/'.now()->format('Y/m').'/'.Str::uuid7().'-'.$variant->value.'.jpg';

            Storage::disk($disk)->put($key, $bytes, 'public');

            /** @var MediaVariantRecord $record */
            $record = MediaVariantRecord::query()->create([
                'stored_file_id' => $file->id,
                'variant' => $variant,
                'visibility' => MediaVisibility::Public,
                'disk' => $disk,
                'storage_key' => $key,
                'mime_type' => 'image/jpeg',
                'byte_size' => strlen($bytes),
                'width' => $width,
                'height' => $height,
                'content_hash' => hash('sha256', $bytes),
                'generated_at' => now(),
            ]);

            $published[] = $record;
        }

        if ($published !== []) {
            /*
             * Audited as its own act. "When did this image become reachable by anybody on the
             * internet, and what made it so" is the question after one turns up somewhere it
             * should not — and the answer must not be inferable only from a post's timestamp.
             */
            $this->audit->recordFile(null, 'media.published', 'Public renditions derived', (string) $file->uuid);
        }

        return $published;
    }

    /**
     * Removes the public objects.
     *
     * Called when content is unpublished, archived or cancelled. Idempotent: withdrawing twice
     * deletes nothing the second time and does not complain.
     */
    public function withdraw(StoredFile $file): void
    {
        $variants = MediaVariantRecord::query()
            ->where('stored_file_id', $file->id)
            ->where('visibility', MediaVisibility::Public->value)
            ->get();

        if ($variants->isEmpty()) {
            return;
        }

        foreach ($variants as $variant) {
            // The object first, then the row. If this fails partway the row still points at what
            // may remain, so a re-run finishes the job — the other order would lose the pointer
            // and leave an orphaned public object nobody can find to delete.
            Storage::disk((string) $variant->disk)->delete((string) $variant->storage_key);
            $variant->delete();
        }

        $this->audit->recordFile(null, 'media.withdrawn', 'Public renditions removed', (string) $file->uuid);
    }

    /**
     * The public URL of a rendition, or null if it is not published.
     *
     * NULL IS A REAL ANSWER, not an error. A client asking for the thumbnail of an image whose
     * post is still a draft gets nothing, which is correct — and it is the same answer it gets
     * for content that never had an image at all, so the absence discloses nothing either.
     */
    public function publicUrl(StoredFile $file, MediaVariant $variant): ?string
    {
        /** @var MediaVariantRecord|null $record */
        $record = MediaVariantRecord::query()
            ->where('stored_file_id', $file->id)
            ->where('variant', $variant->value)
            ->where('visibility', MediaVisibility::Public->value)
            ->first();

        if ($record === null) {
            return null;
        }

        return Storage::disk((string) $record->disk)->url((string) $record->storage_key);
    }

    /**
     * Whether this file may ever have a public rendition.
     *
     * THE REFUSAL LIVES HERE, NOT IN THE CALLER. A module that attached the wrong file to a post
     * — a KYC photograph, a safeguarding image — must not be able to publish it by being wrong,
     * and the module that owns the content is exactly the module least able to judge what the
     * file contains.
     */
    public function mayBePublished(StoredFile $file): bool
    {
        if ($file->purged_at !== null) {
            return false;
        }

        // Only material that names nobody. `Personal` and `Sensitive` are refused outright; so is
        // `Operational`, which is internal working material and was never meant for the public.
        if ($file->classification !== FileClassification::PublicReference) {
            return false;
        }

        if ($file->scan_status === ScanStatus::Infected) {
            return false;
        }

        // Images only. See the class docblock: there is no safe public PDF path, because a PDF
        // cannot be re-encoded through a pixel buffer.
        return str_starts_with((string) $file->mime_type, 'image/');
    }
}
