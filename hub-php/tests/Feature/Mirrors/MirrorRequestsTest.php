<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Identity;
use App\Domain\Mirrors\MirrorRequests;
use App\Domain\Mirrors\Signing;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** Requests to add a mirror, from asking to approval. */
class MirrorRequestsTest extends ModerationTestCase
{
    use RefreshDatabase;

    public const USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    public const URL = 'https://mirror.example.org';

    private string $secret;

    public string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->key = B64::encode(sodium_crypto_sign_publickey_from_secretkey($this->secret));
    }

    public static function identity(): Identity
    {
        return new Identity(fn () => ['93.184.215.14']);
    }

    /** `identity.php` on a mirror holding $secret, or failing with $status. */
    public static function identityAnswer(string $secret, string $id = 'my-mirror', int $status = 200): callable
    {
        return function (Request $request) use ($secret, $id, $status) {
            if ($status !== 200) {
                return self::reply(['error' => 'down'], $status);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $challenge = B64::decode((string) $query['challenge']);

            return self::reply([
                'mirror_id' => $id,
                'base_url' => self::URL,
                'public_key' => B64::encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
                'sig' => B64::encode(Signing::sign(Signing::CTX_IDENTITY, $challenge.self::URL, $secret)),
            ]);
        };
    }

    private function fake(array $mirrors = [], int $identityStatus = 200, array $routes = []): void
    {
        $this->supabase($routes + [
            'GET /rest/v1/mirror' => self::reply($mirrors),
            'identity.php' => self::identityAnswer($this->secret, status: $identityStatus),
        ]);
    }

    private function submit(array $overrides = []): array
    {
        $f = $overrides + ['id' => 'my-mirror', 'name' => 'My Mirror', 'url' => self::URL, 'key' => $this->key];

        return MirrorRequests::submit(self::USER, $f['id'], $f['name'], $f['url'], $f['key'], self::identity());
    }

    public function test_a_proved_request_is_stored(): void
    {
        $this->fake();

        $result = $this->submit();

        $this->assertTrue($result['ok'], $result['message']);
        $row = (array) DB::table('mirror_request')->first();
        $this->assertSame(['my-mirror', 'My Mirror', self::URL, self::USER], [$row['mirror_id'], $row['name'], $row['base_url'], $row['requested_by']]);
    }

    public function test_bad_fields_are_refused_before_any_request(): void
    {
        $this->fake();

        foreach ([
            ['id' => 'Bad_ID'], ['id' => '-x'], ['id' => str_repeat('a', 65)],
            ['name' => ''], ['name' => str_repeat('n', 129)], ['name' => "line\nbreak"], ['name' => "\xff"],
            ['url' => 'http://mirror.example.org'], ['url' => self::URL.'/'],
            ['key' => 'short'], ['key' => str_repeat('!', 43)],
        ] as $overrides) {
            $this->assertFalse($this->submit($overrides)['ok'], json_encode($overrides));
        }
        $this->assertSame([], $this->sent);
        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_an_id_or_key_already_on_the_hub_is_refused(): void
    {
        $this->fake([['id' => 'my-mirror', 'public_key' => 'x']]);
        $this->assertSame('Another mirror already has that ID.', $this->submit()['message']);

        $this->fake([['id' => 'other', 'public_key' => $this->key]]);
        $this->assertSame('A mirror with that public key is already on the hub.', $this->submit()['message']);

        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_a_failed_identity_check_stores_nothing(): void
    {
        $this->fake(identityStatus: 404);

        $result = $this->submit();

        $this->assertFalse($result['ok']);
        $this->assertSame(Identity::MESSAGES['unreachable'], $result['message']);
        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_asking_again_replaces_the_earlier_request(): void
    {
        $this->fake();
        $this->submit(['name' => 'First']);
        $this->submit(['name' => 'Second']);

        $this->assertSame(['Second'], DB::table('mirror_request')->pluck('name')->all());
    }

    public function test_pending_names_the_requester(): void
    {
        $this->fake();
        $this->submit();
        DB::table('profile')->insert(['user_id' => self::USER, 'display_name' => 'Operator', 'created_at' => now(), 'updated_at' => now()]);

        $pending = MirrorRequests::pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Operator', $pending[0]['requester']);
    }

    public function test_approve_rechecks_identity(): void
    {
        $this->fake();
        $this->submit();
        $this->fake(identityStatus: 500);

        $result = MirrorRequests::approve(Supabase::forToken('access'), $this->key, self::identity());

        $this->assertFalse($result['ok']);
        $this->assertSame(1, DB::table('mirror_request')->count());
        $this->assertSame([], $this->sentWrites());
    }

    public function test_approve_adds_the_mirror_and_clears_the_request(): void
    {
        $this->fake();
        $this->submit();
        $this->fake(routes: ['POST /rpc/moderation_add_mirror' => self::reply('added')]);

        $result = MirrorRequests::approve(Supabase::forToken('access'), $this->key, self::identity());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(0, DB::table('mirror_request')->count());
        $add = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'moderation_add_mirror'));
        $this->assertSame(['p_id' => 'my-mirror', 'p_name' => 'My Mirror', 'p_base_url' => self::URL, 'p_public_key' => $this->key], $add->data());
    }

    public function test_approve_keeps_the_request_when_the_hub_refuses_the_mirror(): void
    {
        $this->fake();
        $this->submit();
        $this->fake(routes: ['POST /rpc/moderation_add_mirror' => self::reply('id_taken')]);

        $result = MirrorRequests::approve(Supabase::forToken('access'), $this->key, self::identity());

        $this->assertSame(['ok' => false, 'message' => 'Another mirror already has that ID.'], $result);
        $this->assertSame(1, DB::table('mirror_request')->count());
    }

    public function test_reject_removes_the_request(): void
    {
        $this->fake();
        $this->submit();

        $this->assertTrue(MirrorRequests::reject($this->key)['ok']);
        $this->assertFalse(MirrorRequests::reject($this->key)['ok']);
        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_check_mirror_reports_whether_the_key_is_proved(): void
    {
        $this->fake();
        $mirror = ['id' => 'my-mirror', 'base_url' => self::URL, 'public_key' => $this->key];
        $this->assertTrue(MirrorRequests::checkMirror($mirror, self::identity())['ok']);

        $this->fake(identityStatus: 404);
        $this->assertFalse(MirrorRequests::checkMirror($mirror, self::identity())['ok']);
    }
}
