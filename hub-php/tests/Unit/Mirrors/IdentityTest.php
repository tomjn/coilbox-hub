<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Identity;
use App\Domain\Mirrors\Signing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    private const URL = 'https://mirror.example.org';

    private string $secret;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->key = B64::encode(sodium_crypto_sign_publickey_from_secretkey($this->secret));
    }

    private static function identity(array $addresses = ['93.184.215.14']): Identity
    {
        return new Identity(fn (string $host) => $addresses);
    }

    /** A mirror that answers as `identity.php` does, with fields changed by $override. */
    private function mirror(array $override = [], ?string $signedUrl = null): void
    {
        self::fresh()->fake(['*' => function (Request $request) use ($override, $signedUrl) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $challenge = B64::decode((string) ($query['challenge'] ?? ''));

            return Http::response($override + [
                'mirror_id' => 'my-mirror',
                'base_url' => self::URL,
                'public_key' => $this->key,
                'sig' => B64::encode(Signing::sign(Signing::CTX_IDENTITY, $challenge.($signedUrl ?? self::URL), $this->secret)),
            ]);
        }]);
    }

    /** Http::fake adds to the stubs already set, so each case starts a new fake. */
    private static function fresh(): Factory
    {
        Http::swap(new Factory);

        return Http::getFacadeRoot();
    }

    public function test_a_mirror_that_signs_the_challenge_passes(): void
    {
        $this->mirror();

        $this->assertNull(self::identity()->check(self::URL, 'my-mirror', $this->key));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::URL.'/identity.php?challenge='));
    }

    public function test_each_mismatch_is_named(): void
    {
        $this->mirror(['mirror_id' => 'other']);
        $this->assertSame('wrong_id', self::identity()->check(self::URL, 'my-mirror', $this->key));

        $this->mirror(['base_url' => 'https://elsewhere.example.org']);
        $this->assertSame('wrong_url', self::identity()->check(self::URL, 'my-mirror', $this->key));

        $other = B64::encode(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->mirror();
        $this->assertSame('wrong_key', self::identity()->check(self::URL, 'my-mirror', $other));

        $this->mirror(['sig' => B64::encode(str_repeat("\0", 64))]);
        $this->assertSame('bad_signature', self::identity()->check(self::URL, 'my-mirror', $this->key));

        $this->mirror(['sig' => 42]);
        $this->assertSame('bad_answer', self::identity()->check(self::URL, 'my-mirror', $this->key));
    }

    public function test_signature_for_another_url_fails(): void
    {
        // The mirror claims this URL but signed for its real one, as a relay copying its answer would.
        $this->mirror(signedUrl: 'https://real.example.org');

        $this->assertSame('bad_signature', self::identity()->check(self::URL, 'my-mirror', $this->key));
    }

    public function test_error_status_timeouts_and_redirects_count_as_unreachable(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->assertSame('unreachable', self::identity()->check(self::URL, 'my-mirror', $this->key));

        self::fresh()->fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/identity.php'])]);
        $this->assertSame('unreachable', self::identity()->check(self::URL, 'my-mirror', $this->key));
        Http::assertSentCount(1);

        self::fresh()->fake(['*' => fn () => throw new ConnectionException('timed out')]);
        $this->assertSame('unreachable', self::identity()->check(self::URL, 'my-mirror', $this->key));
    }

    public function test_private_addresses_are_refused(): void
    {
        Http::fake();

        foreach ([['127.0.0.1'], ['10.0.0.1'], ['192.168.1.5'], ['169.254.169.254'], ['::1'], ['fd00::1'], [], ['93.184.215.14', '10.0.0.1']] as $addresses) {
            $this->assertSame('private_address', self::identity($addresses)->check(self::URL, 'my-mirror', $this->key), json_encode($addresses));
        }
        $this->assertSame('private_address', self::identity()->check('https://127.0.0.1', 'my-mirror', $this->key));
        $this->assertSame('private_address', self::identity()->check('https://[::1]:8443', 'my-mirror', $this->key));

        Http::assertNothingSent();
    }

    public function test_bad_urls_are_refused_before_any_lookup(): void
    {
        Http::fake();
        $identity = new Identity(fn () => $this->fail('resolved a bad URL'));

        foreach (['http://mirror.example.org', 'https://mirror.example.org/', 'https://u:p@mirror.example.org', 'https://mirror.example.org?x=1', 'https://mirror.example.org#x', 'https://', 'ftp://mirror.example.org', 'https://mirror .example.org'] as $url) {
            $this->assertSame('bad_url', $identity->check($url, 'my-mirror', $this->key), $url);
        }
        Http::assertNothingSent();
    }
}
