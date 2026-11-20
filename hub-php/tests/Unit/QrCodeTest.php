<?php

namespace Tests\Unit;

use App\Auth\QrCode;
use PHPUnit\Framework\TestCase;

class QrCodeTest extends TestCase
{
    private const URI = 'otpauth://totp/Coilbox:alice?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Coilbox&algorithm=SHA1&digits=6&period=30';

    public function test_it_returns_an_svg_data_uri(): void
    {
        $uri = QrCode::dataUri(self::URI);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($svg);
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_the_picture_follows_the_text(): void
    {
        $this->assertSame(QrCode::dataUri(self::URI), QrCode::dataUri(self::URI));
        $this->assertNotSame(QrCode::dataUri(self::URI), QrCode::dataUri(self::URI.'x'));
    }
}
