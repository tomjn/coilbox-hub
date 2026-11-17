<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\Signing;
use Tests\TestCase;

class SigningTest extends TestCase
{
    use MirrorFixtures;

    public function test_fixture_token_verifies_under_the_fixture_hub_key(): void
    {
        [$payload, $sig] = explode('.', self::fixture('token.txt'));
        $key = B64::decode(self::fixtureKeys()['hub_public_key']);

        $this->assertTrue(Signing::verify(Signing::CTX_TOKEN, B64::decode($payload), B64::decode($sig), $key));
    }

    public function test_fixture_catalogue_sig_verifies_and_fails_under_the_report_context(): void
    {
        $body = self::fixture('catalogue.json');
        $sig = B64::decode(self::fixture('catalogue.sig'));
        $key = B64::decode(self::fixtureKeys()['hub_public_key']);

        $this->assertTrue(Signing::verify(Signing::CTX_CATALOGUE, $body, $sig, $key));
        $this->assertFalse(Signing::verify(Signing::CTX_REPORT, $body, $sig, $key));
    }

    public function test_verify_returns_false_on_wrong_lengths(): void
    {
        $this->assertFalse(Signing::verify(Signing::CTX_TOKEN, 'x', 'short', str_repeat('a', 32)));
        $this->assertFalse(Signing::verify(Signing::CTX_TOKEN, 'x', str_repeat('a', 64), 'short'));
    }

    public function test_b64_rejects_padding_plus_slash_and_trailing_newline(): void
    {
        $this->assertSame('YQ', B64::encode('a'));
        $this->assertSame('a', B64::decode('YQ'));
        $this->assertNull(B64::decode('YQ=='));
        $this->assertNull(B64::decode('+/+/'));
        $this->assertNull(B64::decode("YQ\n"));
        $this->assertNull(B64::decode('Y Q'));
    }

    public function test_hub_keys_null_without_a_valid_secret(): void
    {
        config(['hub.mirrors.secret_key' => '']);
        $this->assertNull(HubKeys::fromConfig());

        config(['hub.mirrors.secret_key' => 'AQEB']);
        $this->assertNull(HubKeys::fromConfig());

        config(['hub.mirrors.secret_key' => '!!!not base64!!!']);
        $this->assertNull(HubKeys::fromConfig());
    }

    public function test_hub_keys_expose_the_fixture_public_key_and_no_next_key(): void
    {
        $keys = self::fixtureKeys();
        config(['hub.mirrors.secret_key' => $keys['hub_secret_key'], 'hub.mirrors.next_secret_key' => '']);

        $hub = HubKeys::fromConfig();

        $this->assertSame($keys['hub_public_key'], B64::encode($hub->publicKey()));
        $this->assertNull($hub->nextKey());
    }

    public function test_next_key_endorsement_matches_the_rotated_fixture(): void
    {
        $keys = self::fixtureKeys();
        config([
            'hub.mirrors.secret_key' => $keys['hub_secret_key'],
            'hub.mirrors.next_secret_key' => $keys['rotated_hub_secret_key'],
        ]);

        $expected = json_decode(self::fixture('catalogue-rotated.json'), true)['next_key'];

        $this->assertSame($expected, HubKeys::fromConfig()->nextKey());
    }

    public function test_invalid_next_key_is_ignored(): void
    {
        $keys = self::fixtureKeys();
        config(['hub.mirrors.secret_key' => $keys['hub_secret_key'], 'hub.mirrors.next_secret_key' => 'AQEB']);

        $this->assertNull(HubKeys::fromConfig()->nextKey());
    }
}
