<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetPath;
use App\Domain\Assets\Caps;
use App\Domain\Assets\ImageHeader;
use Tests\TestCase;

/** Ported from `lib/assets/imageHeader.test.ts` and `lib/assets/caps.test.ts`. */
class ImageAndCapsTest extends TestCase
{
    /** Real encoder output from the Next tests, frozen. */
    private const SAMPLES = [
        'lossyWebp' => 'UklGRjoAAABXRUJQVlA4IC4AAAAQAgCdASoDAAIAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA',
        'losslessWebp' => 'UklGRh4AAABXRUJQVlA4TBEAAAAvA8AAAAdQvFIUuf+BiOh/AAA=',
        'extendedWebp' => 'UklGRl4AAABXRUJQVlA4WAoAAAAQAAAABAAAAgAAQUxQSAoAAAABB1DAiAhERP8DVlA4IC4AAAAQAgCdASoFAAMAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA',
        'gray16Png' => 'iVBORw0KGgoAAAANSUhEUgAAAAYAAAAEEAAAAADY/83cAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAPUlEQVQImWNgYGBguHCBnX3BAn5+hoICcXEHB3l5AQF1dYYHD/T0NmwwM2tosLNjCAhwc1NQ8PP78CE0FABbaQ89PRRsdwAAAABJRU5ErkJggg==',
        'rgb8Png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC',
    ];

    private function sample(string $name): string
    {
        return base64_decode(self::SAMPLES[$name]);
    }

    /** A header over nothing, as the Next tests build them. */
    public static function png(int $width, int $height, int $bitDepth = 8, int $colourType = 2): string
    {
        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height).chr($bitDepth).chr($colourType);
    }

    public static function webp(int $width, int $height, bool $lossless = false): string
    {
        $bytes = str_repeat("\0", 40);
        $bytes = substr_replace($bytes, 'RIFF', 0, 4);
        $bytes = substr_replace($bytes, 'WEBP', 8, 4);
        $bytes = substr_replace($bytes, $lossless ? 'VP8L' : 'VP8 ', 12, 4);
        $bytes = substr_replace($bytes, pack('V', 20), 16, 4);
        if ($lossless) {
            $bytes[20] = "\x2f";

            return substr_replace($bytes, pack('V', ($width - 1) | (($height - 1) << 14)), 21, 4);
        }
        $bytes = substr_replace($bytes, "\x9d\x01\x2a", 23, 3);

        return substr_replace($bytes, pack('vv', $width, $height), 26, 4);
    }

    private function refusal(string $variant, string $mime, string $bytes): string
    {
        $checked = Caps::checkImage($variant, $mime, $bytes);
        $this->assertFalse($checked['ok'], 'expected a refusal');

        return $checked['status'].' '.$checked['error'];
    }

    public function test_the_headers_of_real_encoder_output(): void
    {
        $this->assertEquals(['mime' => 'image/webp', 'width' => 3, 'height' => 2, 'lossless' => false, 'bitDepth' => 8, 'grayscale' => false], ImageHeader::read($this->sample('lossyWebp')));
        $lossless = ImageHeader::read($this->sample('losslessWebp'));
        $this->assertSame([4, 4, true], [$lossless['width'], $lossless['height'], $lossless['lossless']]);
        $this->assertSame(['mime' => 'image/webp', 'lossless' => false, 'bitDepth' => 8, 'grayscale' => false, 'width' => 5, 'height' => 3], ImageHeader::read($this->sample('extendedWebp')));
        $this->assertSame(['mime' => 'image/png', 'width' => 6, 'height' => 4, 'lossless' => true, 'bitDepth' => 16, 'grayscale' => true], ImageHeader::read($this->sample('gray16Png')));
        $rgb = ImageHeader::read($this->sample('rgb8Png'));
        $this->assertSame([8, false, true], [$rgb['bitDepth'], $rgb['grayscale'], $rgb['lossless']]);
    }

    public function test_anything_unmeasurable_is_null(): void
    {
        $this->assertNull(ImageHeader::read('GIF89a'.str_repeat('x', 60)));
        $this->assertNull(ImageHeader::read(''));
        $this->assertNull(ImageHeader::read(substr($this->sample('gray16Png'), 0, 20)));
        $this->assertNull(ImageHeader::read(substr($this->sample('lossyWebp'), 0, 24)));

        $animation = substr_replace($this->sample('extendedWebp'), 'ANMF', 48, 4);
        $this->assertNull(ImageHeader::read($animation));

        // A lossless chunk cut short after its signature byte reads as nothing.
        $this->assertNull(ImageHeader::read(substr(self::webp(4, 4, true), 0, 22).''));
    }

    public function test_a_buildpic_is_square_small_and_lossless(): void
    {
        $this->assertSame(['ok' => true, 'width' => 128, 'height' => 128], Caps::checkImage('buildpic', 'image/webp', self::webp(128, 128, true)));
        $this->assertSame('400 A "buildpic" must be square, and that one is 256x192.', $this->refusal('buildpic', 'image/webp', self::webp(256, 192)));
        $this->assertSame('413 A "buildpic" may be at most 256px on its longest edge, and that one is 512x512.', $this->refusal('buildpic', 'image/webp', self::webp(512, 512)));
        $this->assertSame('400 A "buildpic" must be losslessly encoded, and that one is not.', $this->refusal('buildpic', 'image/webp', self::webp(128, 128)));
    }

    public function test_renders_minimaps_and_overlays(): void
    {
        $this->assertTrue(Caps::checkImage('render:315', 'image/webp', self::webp(192, 128))['ok']);
        $this->assertTrue(Caps::checkImage('render:0', 'image/webp', self::webp(256, 64))['ok']);
        $this->assertSame('413 A "render:315" may be at most 256px on its longest edge, and that one is 320x128.', $this->refusal('render:315', 'image/webp', self::webp(320, 128)));

        $this->assertTrue(Caps::checkImage('minimap', 'image/webp', self::webp(512, 256))['ok']);
        $this->assertSame('413 A "minimap" may be at most 512px on its longest edge, and that one is 600x600.', $this->refusal('minimap', 'image/webp', self::webp(600, 600)));

        $this->assertTrue(Caps::checkImage('overlay:metal', 'image/webp', self::webp(1024, 2048, true))['ok']);
        $this->assertSame('400 A "overlay:type" must be losslessly encoded, and that one is not.', $this->refusal('overlay:type', 'image/webp', self::webp(512, 512)));

        $this->assertTrue(Caps::checkImage('overlay:height', 'image/webp', self::webp(512, 256, true))['ok']);
        $this->assertSame('415 A "overlay:height" must be image/webp, and that one declares image/png.', $this->refusal('overlay:height', 'image/png', self::png(512, 512, 16, 0)));
        $this->assertSame('413 A "overlay:height" may be at most 512px on its longest edge, and that one is 513x513.', $this->refusal('overlay:height', 'image/webp', self::webp(513, 513, true)));
    }

    public function test_types_and_unmeasurable_bytes(): void
    {
        $this->assertSame(['image/webp', 'image/png'], array_keys(AssetPath::MIME_EXTENSIONS));
        $this->assertSame('415 A "buildpic" must be image/webp, and that one declares image/png.', $this->refusal('buildpic', 'image/png', self::png(128, 128)));
        $this->assertSame('415 The declaration says image/webp and the bytes are image/png.', $this->refusal('buildpic', 'image/webp', self::png(128, 128)));
        $this->assertSame('400 Those bytes do not start with a PNG or WebP header the hub can measure.', $this->refusal('minimap', 'image/webp', 'not a picture at all'));
    }

    public function test_byte_caps_derive_from_the_edge(): void
    {
        foreach (Caps::all() as $cap) {
            $this->assertSame($cap['maxEdge'] === null ? null : $cap['maxEdge'] * $cap['maxEdge'] * 4, $cap['maxBytes']);
        }
        $this->assertSame(512 * 512 * 4, Caps::all()['overlay:height']['maxBytes']);
        // The vocabulary carries the same number, and the two must not part company.
        foreach (Asset::vocabulary()['classes'] as $variant => $class) {
            $this->assertSame($class['maxBytes'], Caps::all()[$variant]['maxBytes'], $variant);
        }
    }

    public function test_every_variant_has_a_cap_and_nothing_else_does(): void
    {
        foreach (Asset::mapVariants() as $variant) {
            $this->assertNotNull(Caps::forVariant($variant));
        }
        $this->assertSame(Caps::all()['render'], Caps::forVariant('render:270'));
        $this->assertNull(Caps::forVariant('overlay:hight'));
        $this->assertSame('400 `overlay:hight` is not a variant the hub stores pictures for.', $this->refusal('overlay:hight', 'image/webp', self::webp(64, 64)));
    }

    public function test_a_faction_logo(): void
    {
        $this->assertSame(['ok' => true, 'width' => 128, 'height' => 128], Caps::checkImage('logo', 'image/webp', self::webp(128, 128, true)));
        $this->assertTrue(Caps::checkImage('logo', 'image/webp', self::webp(96, 48, true))['ok']);
        $this->assertSame('413 A "logo" may be at most 128px on its longest edge, and that one is 129x64.', $this->refusal('logo', 'image/webp', self::webp(129, 64, true)));
        $this->assertSame('400 A "logo" must be losslessly encoded, and that one is not.', $this->refusal('logo', 'image/webp', self::webp(64, 64)));
    }
}
