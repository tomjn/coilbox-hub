<?php

namespace App\Auth;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as an SVG data URI, for an `<img>` tag. SVG needs no PHP image
 * extension, only xmlwriter.
 */
final class QrCode
{
    /** The SVG's own size. The page scales it, and the shapes stay sharp. */
    private const SIZE = 256;

    /** Blank modules round the code. Scanners need four. */
    private const QUIET_ZONE = 4;

    public static function dataUri(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE, self::QUIET_ZONE), new SvgImageBackEnd));
        // Level M repairs 15% damage, the level authenticator apps use for their own codes.
        $svg = $writer->writeString($text, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
