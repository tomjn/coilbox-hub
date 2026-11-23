<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use Tests\TestCase;

/** `GET /api/v1/mirrors/hub-key`, the hub's public key for mirror operators and their setup. */
class HubKeyApiTest extends TestCase
{
    private static function secret(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }

    public function test_returns_the_current_public_key(): void
    {
        $secret = self::secret();
        config(['hub.mirrors.secret_key' => B64::encode($secret), 'hub.mirrors.next_secret_key' => '']);

        $response = $this->get('/api/v1/mirrors/hub-key');

        $response->assertOk()->assertExactJson([
            'public_key' => B64::encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_adds_the_next_key_and_its_endorsement_while_rotating(): void
    {
        $secret = self::secret();
        $next = self::secret();
        config(['hub.mirrors.secret_key' => B64::encode($secret), 'hub.mirrors.next_secret_key' => B64::encode($next)]);

        $body = $this->get('/api/v1/mirrors/hub-key')->assertOk()->json();

        $nextPublic = sodium_crypto_sign_publickey_from_secretkey($next);
        $this->assertSame(B64::encode($nextPublic), $body['next']['public_key']);
        $this->assertTrue(Signing::verify(
            Signing::CTX_HUB_KEY,
            $nextPublic,
            B64::decode($body['next']['sig']),
            sodium_crypto_sign_publickey_from_secretkey($secret),
        ));
    }

    public function test_returns_503_when_no_key_is_configured(): void
    {
        config(['hub.mirrors.secret_key' => '']);

        $this->get('/api/v1/mirrors/hub-key')->assertStatus(503);
    }
}
