<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Application\FileStore;
use Modules\Files\Application\MediaPublisher;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Domain\ImageDerivative;
use Modules\Files\Domain\MediaVariant;
use Modules\Files\Domain\MediaVisibility;
use Modules\Files\Infrastructure\Eloquent\MediaVariantRecord;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Shared\Application\ActorContext;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 28, as tests.
 *
 *  1. **Sensitive objects are private by default.**
 *  2. **Public media only becomes public through an explicit content publication workflow.**
 *  3. **EXIF location is not leaked in public images by default.**
 */
final class MediaSecurityTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('object-storage');
        Storage::fake('public-media');

        config()->set('files.disk', 'object-storage');
        config()->set('files.public_disk', 'public-media');
    }

    // ── criterion 3: no location survives, because none can ──────────────────────────

    #[Test]
    public function the_fixture_really_does_carry_gps_coordinates(): void
    {
        /*
         * THE NEGATIVE FIXTURE, AND THE MOST IMPORTANT ASSERTION IN THIS FILE.
         *
         * Everything below proves that coordinates are absent from a derived image. That proof is
         * worthless unless the source had coordinates in the first place — a test that
         * photographs an empty room and reports no people in it.
         */
        $source = $this->jpegWithGpsExif();

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($source));

        $this->assertIsArray($exif, 'The fixture carries no readable EXIF at all.');
        $this->assertArrayHasKey('GPSLatitude', $exif, 'The fixture carries no GPS block to lose.');
    }

    #[Test]
    public function a_derived_image_carries_no_metadata_at_all(): void
    {
        $derived = ImageDerivative::render($this->jpegWithGpsExif(), MediaVariant::Web->maxEdge());

        $this->assertNotNull($derived);

        /*
         * NOT STRIPPED — NEVER PRESENT. The source is decoded to a pixel buffer and a new file is
         * written from it, so there is nowhere for a coordinate to be. That is why this holds for
         * XMP and IPTC too, and for whatever metadata format a phone vendor adds next year: none
         * of them survive a decode, and none of them needed to be known about.
         */
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($derived[0]));

        if (is_array($exif)) {
            $this->assertArrayNotHasKey('GPSLatitude', $exif);
            $this->assertArrayNotHasKey('GPSLongitude', $exif);
        }

        // Belt and braces at the byte level, in case a reader is more forgiving than a parser.
        $this->assertStringNotContainsString('GPS', $derived[0]);
        $this->assertStringNotContainsString('Exif', $derived[0]);
    }

    #[Test]
    public function a_published_public_object_carries_no_coordinates(): void
    {
        [$file] = $this->publishedImage();

        $variant = MediaVariantRecord::query()
            ->where('stored_file_id', $file->id)
            ->where('visibility', MediaVisibility::Public->value)
            ->firstOrFail();

        $bytes = (string) Storage::disk((string) $variant->disk)->get((string) $variant->storage_key);

        // The end-to-end version: a real upload carrying real coordinates, published through the
        // real workflow, read back out of the real public bucket.
        $this->assertStringNotContainsString('GPS', $bytes);
        $this->assertNotSame($this->jpegWithGpsExif(), $bytes, 'The public object is a copy of the original.');
    }

    // ── criterion 1: sensitive objects are private ───────────────────────────────────

    #[Test]
    public function an_upload_never_reaches_the_public_bucket(): void
    {
        $this->storeImage(FileClassification::Personal);
        $this->storeImage(FileClassification::Sensitive);
        $this->storeImage(FileClassification::PublicReference);

        /*
         * NOT ONE OBJECT, of any classification, ever lands here from an upload. The public bucket
         * has exactly one writer — `MediaPublisher` — and it writes only bytes it re-encoded
         * itself, so "do not place sensitive attachments in a public bucket even temporarily" is
         * not a rule anybody has to remember.
         */
        $this->assertSame([], Storage::disk('public-media')->allFiles());
        $this->assertCount(3, Storage::disk('object-storage')->allFiles());
    }

    #[Test]
    public function personal_and_sensitive_material_can_never_be_published(): void
    {
        $publisher = app(MediaPublisher::class);

        foreach ([FileClassification::Personal, FileClassification::Sensitive, FileClassification::Operational] as $class) {
            $file = $this->storeImage($class);

            /*
             * THE REFUSAL LIVES IN FILES, NOT IN THE CALLER. A module that attached a KYC
             * photograph to a newsfeed post must not be able to publish it by being wrong — and
             * the module owning the content is exactly the one least able to judge what the file
             * contains.
             */
            $this->assertFalse($publisher->mayBePublished($file), $class->value.' must never be publishable');
            $this->assertSame([], $publisher->publish($file));
        }

        $this->assertSame([], Storage::disk('public-media')->allFiles());
    }

    #[Test]
    public function a_pdf_has_no_public_path(): void
    {
        $file = $this->storeUpload(
            UploadedFile::fake()->createWithContent('form.pdf', '%PDF-1.4 body'),
            FileClassification::PublicReference,
        );

        /*
         * A PDF cannot be re-encoded through a pixel buffer, so its metadata would have to be
         * *stripped* — and a stripper that misses a field leaks silently. Refusing the whole
         * format is the honest answer.
         */
        $this->assertFalse(app(MediaPublisher::class)->mayBePublished($file));
    }

    #[Test]
    public function no_public_variant_row_points_at_an_unpublishable_file(): void
    {
        $this->publishedImage();
        $this->storeImage(FileClassification::Sensitive);

        /*
         * Read from the database rather than from the disk, because the row is what the API
         * consults when it decides whether to hand out a URL. A public object with no row is
         * unreachable; a row with no object is a broken image. Both are wrong, and this catches
         * the one that discloses.
         */
        $public = MediaVariantRecord::query()->where('visibility', MediaVisibility::Public->value)->get();

        $this->assertNotEmpty($public);

        foreach ($public as $variant) {
            $file = StoredFile::query()->findOrFail($variant->stored_file_id);

            $this->assertSame(FileClassification::PublicReference, $file->classification);
            $this->assertSame('image/jpeg', $variant->mime_type);
        }
    }

    // ── criterion 2: publication is the only route ───────────────────────────────────

    #[Test]
    public function a_draft_posts_image_is_not_publicly_reachable(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $file = $this->storeImage(FileClassification::PublicReference);

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'An unreleased advisory.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) $file->uuid,
            'alt_text' => 'Queue outside the barangay hall.',
        ])->assertOk();

        // Attached to a DRAFT. Nothing has been published, so nothing is public.
        $this->assertSame([], Storage::disk('public-media')->allFiles());
        $this->assertSame([], app(DocumentLibrary::class)->publicMediaUrls((string) $file->uuid));
    }

    #[Test]
    public function publishing_the_post_is_what_makes_the_image_public(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$post, $file] = $this->postWithImage();

        $this->assertSame([], Storage::disk('public-media')->allFiles());

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();

        // Two renditions — a thumbnail for a feed row and a web size for the detail view.
        $this->assertCount(2, Storage::disk('public-media')->allFiles());

        $urls = app(DocumentLibrary::class)->publicMediaUrls((string) $file->uuid);
        $this->assertArrayHasKey('thumbnail', $urls);
        $this->assertArrayHasKey('web', $urls);
    }

    #[Test]
    public function archiving_the_post_takes_the_image_down(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        [$post, $file] = $this->postWithImage();
        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'published'])->assertOk();
        $this->assertCount(2, Storage::disk('public-media')->allFiles());

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", ['status' => 'archived'])->assertOk();

        /*
         * THE DIRECTION THAT MATTERS MORE. A post taken down whose image stayed at a public URL
         * would be a takedown that did not take anything down — and the URL is the part that gets
         * shared, screenshotted and indexed.
         */
        $this->assertSame([], Storage::disk('public-media')->allFiles());
        $this->assertSame([], app(DocumentLibrary::class)->publicMediaUrls((string) $file->uuid));

        // The ORIGINAL is untouched throughout. Publication never moved it.
        $this->assertCount(1, Storage::disk('object-storage')->allFiles());
    }

    #[Test]
    public function publishing_twice_derives_nothing_twice(): void
    {
        [$file] = $this->publishedImage();

        $publisher = app(MediaPublisher::class);
        $again = $publisher->publish($file);

        // A post edited and republished, or a job retried, must not accumulate objects.
        $this->assertCount(2, $again);
        $this->assertCount(2, Storage::disk('public-media')->allFiles());
    }

    #[Test]
    public function an_event_cover_follows_the_events_visibility(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $file = $this->storeImage(FileClassification::PublicReference);

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'cover_file_id' => (string) $file->uuid,
            'cover_alt_text' => 'Residents queueing outside the covered court.',
        ])->assertCreated()->json('data.id');

        $this->assertSame([], Storage::disk('public-media')->allFiles());

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();
        $this->assertCount(2, Storage::disk('public-media')->allFiles());

        /*
         * A CANCELLED EVENT KEEPS ITS COVER. It stays on the public list with its reason showing
         * (ADR 0030 §3), and a listing that lost its image the moment it was called off would
         * look broken to exactly the people who most need to read it.
         */
        $this->postJson("/api/v1/admin/events/{$event}/status", [
            'status' => 'cancelled',
            'reason' => 'Typhoon signal 2.',
        ])->assertOk();
        $this->assertCount(2, Storage::disk('public-media')->allFiles());

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'archived'])->assertOk();
        $this->assertSame([], Storage::disk('public-media')->allFiles());
    }

    // ── the rest of the storage rules ────────────────────────────────────────────────

    #[Test]
    public function a_public_storage_key_reveals_nothing_about_the_upload(): void
    {
        $this->publishedImage();

        foreach (Storage::disk('public-media')->allFiles() as $key) {
            /*
             * On a PUBLIC bucket an opaque key matters twice over: a guessable one is a directory
             * listing for anybody who wants it, and a filename-derived one would publish what a
             * resident called the file.
             */
            $this->assertStringNotContainsString('barangay-hall', $key);
            $this->assertMatchesRegularExpression('#^media/\d{4}/\d{2}/[0-9a-f-]+-(thumbnail|web)\.jpg$#', $key);
        }
    }

    #[Test]
    public function the_size_limit_is_per_context(): void
    {
        $oversized = UploadedFile::fake()->createWithContent(
            'poster.jpg',
            $this->jpegWithGpsExif().str_repeat("\0", 5 * 1024 * 1024),
        );

        /*
         * PER CONTEXT, not one global ceiling. A 5 MB advisory image is refused because it is
         * going on a page people open on mobile data; the same bytes as a resident's multi-page
         * scan are accepted, because a rejection there is a trip back to a photocopier.
         */
        $this->expectExceptionMessage('larger than this endpoint accepts');
        $this->storeUpload($oversized, FileClassification::PublicReference);
    }

    #[Test]
    public function the_same_bytes_uploaded_twice_are_flagged_and_not_refused(): void
    {
        $first = $this->storeImage(FileClassification::Personal);
        $second = $this->storeImage(FileClassification::Personal);

        /*
         * Detected, recorded, and ACCEPTED. Re-sending one barangay clearance against a second
         * requirement is legitimate; what the office wants is to be told, not to have the resident
         * blocked.
         */
        $this->assertNull($first->duplicate_of_file_id);
        $this->assertSame((string) $first->uuid, (string) $second->refresh()->duplicate_of_file_id);
    }

    #[Test]
    public function a_sideways_photograph_comes_out_upright(): void
    {
        // 40 wide, 20 tall, tagged "rotate 90°" — what a phone held sideways produces.
        $source = $this->jpegWithOrientation(6);

        $derived = ImageDerivative::render($source, 2000);

        $this->assertNotNull($derived);

        /*
         * THE TRAP IN THE WHOLE APPROACH. Re-encoding drops the orientation tag, so a rotation
         * that was previously described by metadata has to be baked into the pixels first —
         * otherwise an image that displayed correctly before processing displays on its side
         * afterwards, and the file is genuinely valid so nothing looks wrong.
         */
        $this->assertSame(20, $derived[1], 'width after rotation');
        $this->assertSame(40, $derived[2], 'height after rotation');
    }

    #[Test]
    public function a_derived_rendition_is_bounded_by_its_variants_longest_edge(): void
    {
        $source = (string) file_get_contents($this->jpegPath(1600, 900));

        foreach (MediaVariant::all() as $variant) {
            $derived = ImageDerivative::render($source, $variant->maxEdge());

            $this->assertNotNull($derived);
            $this->assertLessThanOrEqual($variant->maxEdge(), max($derived[1], $derived[2]));
            // Proportions kept: constraining the longest edge is what stops a portrait poster
            // being squashed into a landscape box.
            $this->assertEqualsWithDelta(1600 / 900, $derived[1] / $derived[2], 0.02);
        }
    }

    #[Test]
    public function a_small_image_is_not_enlarged(): void
    {
        $source = (string) file_get_contents($this->jpegPath(100, 80));

        $derived = ImageDerivative::render($source, MediaVariant::Web->maxEdge());

        // Upscaling a blurry photograph makes a bigger blurry photograph and a larger file to
        // send to a phone.
        $this->assertSame([100, 80], [$derived[1], $derived[2]]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: StoredFile}
     */
    private function publishedImage(): array
    {
        $file = $this->storeImage(FileClassification::PublicReference);

        app(MediaPublisher::class)->publish($file);

        return [$file];
    }

    /**
     * @return array{0: string, 1: StoredFile}
     */
    private function postWithImage(): array
    {
        $file = $this->storeImage(FileClassification::PublicReference);

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/media", [
            'file_id' => (string) $file->uuid,
            'alt_text' => 'Queue outside the barangay hall.',
        ])->assertOk();

        return [$post, $file];
    }

    private function storeImage(FileClassification $classification): StoredFile
    {
        return $this->storeUpload(
            UploadedFile::fake()->createWithContent('barangay-hall.jpg', $this->jpegWithGpsExif()),
            $classification,
        );
    }

    private function storeUpload(UploadedFile $upload, FileClassification $classification): StoredFile
    {
        return app(FileStore::class)->store(
            $upload,
            $classification,
            ActorContext::authenticated('11111111-1111-1111-1111-111111111111'),
        );
    }

    /**
     * A real JPEG carrying a real GPS EXIF block.
     *
     * Built by hand rather than checked in, because a binary fixture in a repository is a fixture
     * nobody can read or verify — and this one's entire job is to contain something specific.
     */
    private function jpegWithGpsExif(): string
    {
        return $this->jpegWithExifPayload($this->gpsExifPayload());
    }

    private function jpegWithOrientation(int $orientation): string
    {
        return $this->jpegWithExifPayload($this->orientationExifPayload($orientation), 40, 20);
    }

    private function jpegWithExifPayload(string $exif, int $width = 600, int $height = 400): string
    {
        $jpeg = (string) file_get_contents($this->jpegPath($width, $height));

        // Splice the APP1 segment in immediately after SOI, which is where a camera puts it.
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * A minimal little-endian TIFF header with one GPS IFD pointer and a latitude.
     */
    private function gpsExifPayload(): string
    {
        // TIFF header, then one IFD entry pointing at the GPS IFD.
        $tiff = "II\x2A\x00\x08\x00\x00\x00";
        $tiff .= pack('v', 1);                       // one entry
        $tiff .= pack('vvVV', 0x8825, 4, 1, 26);     // GPSInfoIFDPointer -> offset 26
        $tiff .= pack('V', 0);                       // no next IFD

        $gps = pack('v', 1);                         // one GPS entry
        $gps .= pack('vvVV', 2, 5, 3, 26 + 2 + 12 + 4); // GPSLatitude, 3 rationals
        $gps .= pack('V', 0);
        // 14.5586 N as degrees/minutes/seconds rationals.
        $gps .= pack('VVVVVV', 14, 1, 33, 1, 31, 1);

        return "Exif\x00\x00".$tiff.$gps;
    }

    private function orientationExifPayload(int $orientation): string
    {
        $tiff = "II\x2A\x00\x08\x00\x00\x00";
        $tiff .= pack('v', 1);
        $tiff .= pack('vvVv', 0x0112, 3, 1, $orientation).pack('v', 0);
        $tiff .= pack('V', 0);

        return "Exif\x00\x00".$tiff;
    }

    private function jpegPath(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 180, 160));

        $path = tempnam(sys_get_temp_dir(), 'lguids').'.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }
}
