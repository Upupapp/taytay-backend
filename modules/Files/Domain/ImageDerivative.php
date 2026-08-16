<?php

declare(strict_types=1);

namespace Modules\Files\Domain;

/**
 * Re-encodes an image from decoded pixels (ADR 0033 §2).
 *
 * **EXIF IS NOT STRIPPED HERE. IT IS NEVER PRESENT.**
 *
 * That distinction is the entire point of this class, and it is worth being precise about because
 * the two approaches look identical from outside and fail completely differently.
 *
 * *Stripping* means taking the original bytes and removing the metadata segments — which requires
 * knowing every segment that can carry location. JPEG alone can hold coordinates in EXIF, in XMP
 * and in an IPTC block; PNG can hold them in `eXIf`, in `tEXt` and in `iTXt`. A stripper that
 * knows five of six is a stripper that leaks, and it leaks silently, and the sixth is whatever a
 * phone vendor adds next year.
 *
 * *Re-encoding* decodes the file to a raw pixel buffer and writes a new file from it. The buffer
 * is width × height × colour. **There is nowhere for a coordinate to be**, so the output has none
 * — not because anything removed it, but because the intermediate representation cannot express
 * it. A new metadata format invented next year changes nothing.
 *
 * ORIENTATION MUST BE APPLIED BEFORE THE METADATA GOES, and this is the trap in the whole
 * approach. A phone photographed sideways is stored upright with an EXIF `Orientation` tag saying
 * "rotate me". Re-encoding drops that tag — so an image that displayed correctly before
 * processing displays on its side afterwards, and the cause is invisible because the file is
 * genuinely valid. The rotation is therefore read from the source and baked into the pixels
 * first, which is also what the master command means by "orientation normalization".
 */
final class ImageDerivative
{
    /** JPEG quality for derived renditions. High enough that text in a scanned form stays legible. */
    private const QUALITY = 82;

    /**
     * Whether an image can be derived at all in this environment.
     *
     * Checked rather than assumed: without GD there is no decode, and the honest response is to
     * produce no public variant at all. Silently copying the original instead would put an
     * EXIF-carrying file in a public bucket — the exact failure this class exists to prevent —
     * and it would happen only on the one host where the extension was missing.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Produces a re-encoded rendition, or null if the source cannot be decoded.
     *
     * @param  int  $maxEdge  the longest side of the result; smaller images are NOT enlarged,
     *                        because upscaling a blurry photograph makes a bigger blurry
     *                        photograph and a larger file to send to a phone.
     * @return array{0: string, 1: int, 2: int}|null bytes, width, height
     */
    public static function render(string $source, int $maxEdge): ?array
    {
        if (! self::isAvailable()) {
            return null;
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            return null;
        }

        try {
            $image = self::applyOrientation($image, $source);

            $width = imagesx($image);
            $height = imagesy($image);
            $longest = max($width, $height);

            if ($longest > $maxEdge) {
                $scale = $maxEdge / $longest;
                $target = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

                if ($target !== false) {
                    imagedestroy($image);
                    $image = $target;
                    $width = imagesx($image);
                    $height = imagesy($image);
                }
            }

            /*
             * ALWAYS JPEG, whatever came in.
             *
             * One output format means one encoder to reason about, and the PNG chunk types that
             * can carry text are simply never written. It also means a 6 MB PNG screenshot of a
             * form becomes a few hundred kilobytes on a phone.
             *
             * Transparency is flattened onto white rather than lost to a black background, which
             * is what an un-flattened PNG-to-JPEG conversion produces and what makes a logo look
             * like a mistake.
             */
            $flattened = imagecreatetruecolor($width, $height);
            imagefill($flattened, 0, 0, (int) imagecolorallocate($flattened, 255, 255, 255));
            imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

            ob_start();
            imagejpeg($flattened, null, self::QUALITY);
            $bytes = (string) ob_get_clean();

            imagedestroy($flattened);

            return $bytes === '' ? null : [$bytes, $width, $height];
        } finally {
            if (is_object($image)) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Bakes the EXIF orientation into the pixels.
     *
     * Only the four rotations are handled. The mirrored orientations (2, 4, 5, 7) exist in the
     * specification and essentially never occur outside deliberately crafted files; treating one
     * as its unmirrored equivalent shows the image the right way up, which is better than the
     * alternative of implementing four more branches nobody can test against a real photograph.
     */
    private static function applyOrientation(\GdImage $image, string $source): \GdImage
    {
        if (! extension_loaded('exif')) {
            return $image;
        }

        // Read from the SOURCE BYTES rather than from a caller-supplied value: the whole design
        // of this module is that a file's properties are read from the file.
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($source));

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
