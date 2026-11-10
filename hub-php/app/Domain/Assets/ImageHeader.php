<?php

namespace App\Domain\Assets;

/**
 * What the bytes of an upload are, read out of the first few kilobytes,
 * `lib/assets/imageHeader.ts` in the Next app.
 *
 * Format only, and shallow on purpose: the dimensions are in the first header
 * of both PNG and WebP, so no decoder ever sees untrusted bytes. A file that
 * lies past its header fails to render and the moderation queue catches it.
 */
final class ImageHeader
{
    /**
     * How much of the file to read. The margin is for extended WebP, where a
     * browser encoder can put an alpha chunk before the image chunk. One was
     * measured at byte 4128 in the Next app, past the old 4096 bound.
     */
    public const BYTES = 65536;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * What the bytes say they are, or null when they are not a PNG or WebP the
     * hub can measure.
     *
     * @return array{mime: string, width: int, height: int, lossless: bool, bitDepth: int, grayscale: bool}|null
     */
    public static function read(string $bytes): ?array
    {
        $header = self::png($bytes) ?? self::webp($bytes);
        if ($header === null) {
            return null;
        }

        return $header['width'] > 0 && $header['height'] > 0 ? $header : null;
    }

    /** IHDR must be the first chunk, so everything is at a fixed offset. Colour types 0 and 4 are grayscale. */
    private static function png(string $b): ?array
    {
        if (strlen($b) < 26 || ! str_starts_with($b, self::PNG_SIGNATURE) || substr($b, 12, 4) !== 'IHDR') {
            return null;
        }
        $colourType = ord($b[25]);

        return [
            'mime' => 'image/png',
            'width' => unpack('N', $b, 16)[1],
            'height' => unpack('N', $b, 20)[1],
            'lossless' => true,
            'bitDepth' => ord($b[24]),
            'grayscale' => $colourType === 0 || $colourType === 4,
        ];
    }

    /**
     * The still image chunk and where its payload starts. An extended file may
     * put metadata or an alpha plane first, so the chunks are walked. An
     * animation has no top level image chunk and comes back null.
     *
     * @return array{kind: string, at: int}|null
     */
    private static function findImageChunk(string $b): ?array
    {
        $at = 12;
        $length = strlen($b);
        while ($at + 8 <= $length) {
            $kind = substr($b, $at, 4);
            $size = unpack('V', $b, $at + 4)[1];
            if ($kind === 'VP8 ' || $kind === 'VP8L') {
                return ['kind' => $kind, 'at' => $at + 8];
            }
            $at += 8 + $size + ($size % 2);
        }

        return null;
    }

    /**
     * WebP: lossy `VP8 `, lossless `VP8L`, or the extended `VP8X` container,
     * whose canvas size is what the file is.
     */
    private static function webp(string $b): ?array
    {
        if (strlen($b) < 30 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $image = self::findImageChunk($b);
        if ($image === null) {
            return null;
        }
        $common = ['mime' => 'image/webp', 'lossless' => $image['kind'] === 'VP8L', 'bitDepth' => 8, 'grayscale' => false];

        if (substr($b, 12, 4) === 'VP8X') {
            return [...$common, 'width' => self::u24le($b, 24) + 1, 'height' => self::u24le($b, 27) + 1];
        }

        if ($image['kind'] === 'VP8L') {
            // Reading past the end gives undefined, which JavaScript arithmetic turns into a refusal.
            if (self::byte($b, $image['at']) !== 0x2F || strlen($b) < $image['at'] + 5) {
                return null;
            }
            $packed = unpack('V', $b, $image['at'] + 1)[1];

            return [...$common, 'width' => ($packed & 0x3FFF) + 1, 'height' => (($packed >> 14) & 0x3FFF) + 1];
        }

        $sync = $image['at'] + 3;
        if (self::byte($b, $sync) !== 0x9D || self::byte($b, $sync + 1) !== 0x01 || self::byte($b, $sync + 2) !== 0x2A || strlen($b) < $image['at'] + 10) {
            return null;
        }

        return [
            ...$common,
            'width' => unpack('v', $b, $image['at'] + 6)[1] & 0x3FFF,
            'height' => unpack('v', $b, $image['at'] + 8)[1] & 0x3FFF,
        ];
    }

    private static function byte(string $b, int $at): ?int
    {
        return $at < strlen($b) ? ord($b[$at]) : null;
    }

    private static function u24le(string $b, int $at): int
    {
        return ord($b[$at]) | (ord($b[$at + 1]) << 8) | (ord($b[$at + 2]) << 16);
    }
}
