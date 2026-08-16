<?php

declare(strict_types=1);

namespace Modules\Files\Domain;

/**
 * What this system accepts as an uploaded document, and how it proves it.
 *
 * THE DECLARED TYPE IS NOT EVIDENCE. A browser's `Content-Type` and a filename extension are
 * both supplied by the caller, so a `.pdf` that is really an HTML file, or a `.jpg` that is
 * really a PHP script, arrives looking correct in both. The leading bytes of a real file are
 * written by the encoder that produced it, so they are matched here and a mismatch is refused.
 *
 * Both clients already run this same check before uploading. **Theirs is a courtesy and this one
 * is the boundary** — the resident mobile client says so explicitly in
 * `DocumentCapturePolicy.inspect`. A client-side check tells somebody something useful before a
 * slow upload; it proves nothing about what actually arrived.
 *
 * The three types are the municipal office's own list, agreed by both clients: a photo of a
 * document, or a PDF of one. Adding a fourth is a policy decision, not a convenience.
 */
enum AcceptedMediaType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Pdf = 'application/pdf';

    /**
     * The maximum an upload may be, in bytes.
     *
     * 10 MiB, matching both clients exactly. Generous enough for a multi-page scanned PDF, small
     * enough that somebody on a weak mobile connection is not uploading for minutes.
     *
     * **This number must be smaller than the reverse proxy's `client_max_body_size`.** If nginx
     * rejects the body first it answers 413 without running PHP — and therefore without CORS
     * headers — so a browser sees a network failure with status 0 rather than a message anybody
     * can act on. The runbook carries the required nginx value; this constant is the one that
     * should win.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * The signature every file of this type begins with.
     *
     * @return list<int>
     */
    public function signature(): array
    {
        return match ($this) {
            self::Jpeg => [0xFF, 0xD8, 0xFF],
            self::Png => [0x89, 0x50, 0x4E, 0x47],
            // "%PDF"
            self::Pdf => [0x25, 0x50, 0x44, 0x46],
        };
    }

    /**
     * The extension used to build the stored filename.
     *
     * Derived from the verified type, never from what the caller named the file. A caller-chosen
     * extension is a caller-chosen path component, and that is how an upload becomes a write to
     * somewhere it should not reach.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Pdf => 'pdf',
        };
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }

    /**
     * Identifies the content by reading it, or returns null if it is nothing we accept.
     */
    public static function detect(string $contents): ?self
    {
        foreach (self::cases() as $type) {
            if (self::startsWith($contents, $type->signature())) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * @param  list<int>  $signature
     */
    private static function startsWith(string $contents, array $signature): bool
    {
        if (strlen($contents) < count($signature)) {
            return false;
        }

        foreach ($signature as $offset => $byte) {
            if (ord($contents[$offset]) !== $byte) {
                return false;
            }
        }

        return true;
    }
}
