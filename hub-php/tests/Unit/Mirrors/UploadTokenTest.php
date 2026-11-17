<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\UploadToken;
use PHPUnit\Framework\TestCase;

class UploadTokenTest extends TestCase
{
    use MirrorFixtures;

    public function test_token_matches_the_fixture_byte_for_byte(): void
    {
        $p = json_decode(self::fixture('token.payload.json'), true, flags: JSON_THROW_ON_ERROR);

        $token = UploadToken::issue(
            $p['mirror'], $p['upload'], $p['sha256'], $p['size'], $p['filename'], $p['kind'], $p['start_by'],
            sodium_base642bin(self::fixtureKeys()['hub_secret_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        );

        $this->assertSame(self::fixture('token.txt'), $token);
    }
}
