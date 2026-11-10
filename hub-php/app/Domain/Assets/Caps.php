<?php

namespace App\Domain\Assets;

use RuntimeException;

/**
 * What each class of picture may be, checked against the bytes rather than
 * the declaration, `lib/assets/caps.ts` in the Next app.
 *
 * Read from the vendored vocabulary. `render` covers every `render:<angle>`.
 * `maxBytes` is derived from `maxEdge` as four bytes a pixel, so no honest
 * encoding reaches it. The metal and type overlays have no pixel cap, because
 * they stay at the resolution of the map's own grids.
 */
final class Caps
{
    /** @var array<string, array{mime: string, maxEdge: int|null, maxBytes: int|null, square: bool, lossless: bool, minBitDepth: int|null, grayscale: bool}>|null */
    private static ?array $caps = null;

    /** @return array<string, array{mime: string, maxEdge: int|null, maxBytes: int|null, square: bool, lossless: bool, minBitDepth: int|null, grayscale: bool}> */
    public static function all(): array
    {
        if (self::$caps !== null) {
            return self::$caps;
        }
        $caps = [];
        foreach (Asset::vocabulary()['classes'] as $variant => $agreed) {
            // A type the hub cannot name a file after is one it cannot store.
            if (! AssetPath::isAssetMime($agreed['mime'])) {
                throw new RuntimeException('The asset vocabulary encodes "'.$variant.'" as '.$agreed['mime'].', which the hub has no extension for.');
            }
            $caps[$variant] = [
                'mime' => $agreed['mime'],
                'maxEdge' => $agreed['maxEdgePx'],
                'maxBytes' => $agreed['maxEdgePx'] === null ? null : $agreed['maxEdgePx'] * $agreed['maxEdgePx'] * 4,
                'square' => $agreed['square'],
                'lossless' => $agreed['lossless'],
                'minBitDepth' => $agreed['minBitDepth'],
                'grayscale' => $agreed['grayscale'],
            ];
        }

        return self::$caps = $caps;
    }

    /** @return array{mime: string, maxEdge: int|null, maxBytes: int|null, square: bool, lossless: bool, minBitDepth: int|null, grayscale: bool}|null */
    public static function forVariant(string $variant): ?array
    {
        if (str_starts_with($variant, Asset::unitRenderVariantPrefix())) {
            return self::all()['render'];
        }

        return self::all()[$variant] ?? null;
    }

    /**
     * Measure the bytes and hold them to their class. The declared MIME is
     * checked against the class and against what the bytes are, because it
     * picks the stored file's extension.
     *
     * @return array{ok: true, width: int, height: int}|array{ok: false, error: string, status: int}
     */
    public static function checkImage(string $variant, string $declared, string $bytes): array
    {
        $cap = self::forVariant($variant);
        if ($cap === null) {
            return ['ok' => false, 'error' => '`'.$variant.'` is not a variant the hub stores pictures for.', 'status' => 400];
        }
        if ($declared !== $cap['mime']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" must be '.$cap['mime'].', and that one declares '.$declared.'.', 'status' => 415];
        }

        $header = ImageHeader::read($bytes);
        if ($header === null) {
            return ['ok' => false, 'error' => 'Those bytes do not start with a PNG or WebP header the hub can measure.', 'status' => 400];
        }
        if ($header['mime'] !== $declared) {
            return ['ok' => false, 'error' => 'The declaration says '.$declared.' and the bytes are '.$header['mime'].'.', 'status' => 415];
        }

        $size = $header['width'].'x'.$header['height'];
        if ($cap['maxEdge'] !== null && max($header['width'], $header['height']) > $cap['maxEdge']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" may be at most '.$cap['maxEdge'].'px on its longest edge, and that one is '.$size.'.', 'status' => 413];
        }
        if ($cap['square'] && $header['width'] !== $header['height']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" must be square, and that one is '.$size.'.', 'status' => 400];
        }
        if ($cap['lossless'] && ! $header['lossless']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" must be losslessly encoded, and that one is not.', 'status' => 400];
        }
        if ($cap['minBitDepth'] !== null && $header['bitDepth'] < $cap['minBitDepth']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" must carry '.$cap['minBitDepth'].' bits a channel, and that one carries '.$header['bitDepth'].'.', 'status' => 400];
        }
        if ($cap['grayscale'] && ! $header['grayscale']) {
            return ['ok' => false, 'error' => 'A "'.$variant.'" must be grayscale, and that one is not.', 'status' => 400];
        }

        return ['ok' => true, 'width' => $header['width'], 'height' => $header['height']];
    }
}
