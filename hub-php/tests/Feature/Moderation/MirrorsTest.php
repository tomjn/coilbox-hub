<?php

namespace Tests\Feature\Moderation;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Identity;
use App\Support\HubCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Mirrors\MirrorRequestsTest;

/** `/moderation/mirrors`, listing the hub's mirrors, adding one and removing one. */
class MirrorsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const KEY = 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY';

    private static function mirror(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'jj-darkstars',
            'name' => 'jj.darkstars.co.uk',
            'base_url' => 'https://jj.darkstars.co.uk',
            'public_key' => self::KEY,
            'enabled' => true,
            'healthy' => true,
            'last_report_at' => '2026-11-18T10:00:00+00:00',
            'last_check_at' => '2026-11-18T10:00:00+00:00',
            'last_catalogue_seq' => 12,
            'created_at' => '2026-11-17T12:00:00+00:00',
            'copies' => 3,
            'uploads' => 0,
        ];
    }

    /** Whether a value cached under the maps tag before $act is still served after it. */
    private function cachedPagesSurvive(callable $act): bool
    {
        HubCache::remember('maps:test', [HubCache::MAPS], 60, fn () => 'before');
        $act();

        return HubCache::remember('maps:test', [HubCache::MAPS], 60, fn () => 'after') === 'before';
    }

    public function test_a_signed_out_visitor_and_a_stranger_get_404_and_write_nothing(): void
    {
        $this->supabase(moderator: false);

        $this->get('/moderation/mirrors')->assertNotFound();
        $this->signedIn()->get('/moderation/mirrors')->assertNotFound();
        $this->signedIn()->post('/moderation/mirrors/add', ['id' => 'x', 'name' => 'X', 'base_url' => 'https://x.test', 'public_key' => self::KEY]);
        $this->signedIn()->post('/moderation/mirrors/remove', ['mirror_id' => 'jj-darkstars']);
        $this->signedIn()->post('/moderation/mirrors/enable', ['mirror_id' => 'jj-darkstars']);
        $this->insertRequest();
        $this->signedIn()->post('/moderation/mirrors/requests/approve', ['public_key' => self::KEY]);
        $this->signedIn()->post('/moderation/mirrors/requests/reject', ['public_key' => self::KEY]);
        $this->signedIn()->post('/moderation/mirrors/check', ['mirror_id' => 'jj-darkstars']);

        $this->assertSame([], $this->sentWrites());
        $this->assertSame(1, DB::table('mirror_request')->count());
        $this->assertFalse(collect($this->sent)->contains(fn (Request $r) => str_contains($r->url(), 'identity.php')));
    }

    public function test_the_page_shows_each_mirror_with_its_health(): void
    {
        $this->supabase(['POST /rpc/moderation_mirrors' => self::reply(['seq' => 14, 'mirrors' => [
            self::mirror(),
            self::mirror(['id' => 'new-one', 'name' => 'New One', 'healthy' => false, 'last_report_at' => null, 'last_check_at' => null, 'last_catalogue_seq' => null, 'copies' => 0]),
            self::mirror(['id' => 'broken', 'name' => 'Broken', 'healthy' => false, 'last_catalogue_seq' => 14, 'copies' => 1]),
            self::mirror(['id' => 'off', 'name' => 'Off', 'enabled' => false, 'uploads' => 2]),
            self::mirror(['id' => 'took-uploads', 'name' => 'Took Uploads', 'uploads' => 4]),
        ]])]);

        $response = $this->signedIn()->get('/moderation/mirrors');

        $this->assertMatchesRegularExpression('#href="/moderation/mirrors"\s+aria-current="page"#', $response->getContent());
        $response
            ->assertOk()
            ->assertSee('<title>Mirrors - Moderation - Coilbox Hub</title>', false)
            ->assertSeeInOrder(['jj.darkstars.co.uk', 'Healthy', 'Synced number 12, the hub is on 14', '3 archives', 'Remove jj.darkstars.co.uk'])
            ->assertSeeInOrder(['New One', 'Waiting for its first report', 'Not synced yet', '0 archives'])
            ->assertSeeInOrder(['Broken', 'Failed its last check', 'Up to date, number 14', '1 archive'])
            ->assertSeeInOrder(['Off', 'Switched off', 'Switch Off back on'])
            ->assertSee('Switch off Took Uploads')
            ->assertDontSee('Remove Off')
            ->assertSee('Add the mirror');
    }

    public function test_the_page_shows_the_hub_public_key_and_the_next_one_while_rotating(): void
    {
        $current = sodium_crypto_sign_keypair();
        $next = sodium_crypto_sign_keypair();
        $this->supabase(['POST /rpc/moderation_mirrors' => self::reply(['seq' => 1, 'mirrors' => []])]);

        config(['hub.mirrors.secret_key' => '', 'hub.mirrors.next_secret_key' => '']);
        $this->signedIn()->get('/moderation/mirrors')->assertSee('The hub has no valid signing key set');

        config(['hub.mirrors.secret_key' => B64::encode(sodium_crypto_sign_secretkey($current))]);
        $this->signedIn()->get('/moderation/mirrors')
            ->assertSee('value="'.B64::encode(sodium_crypto_sign_publickey($current)).'"', false)
            ->assertDontSee('next public key');

        config(['hub.mirrors.next_secret_key' => B64::encode(sodium_crypto_sign_secretkey($next))]);
        $this->signedIn()->get('/moderation/mirrors')
            ->assertSee('value="'.B64::encode(sodium_crypto_sign_publickey($current)).'"', false)
            ->assertSee('value="'.B64::encode(sodium_crypto_sign_publickey($next)).'"', false);
    }

    public function test_an_unreadable_list_says_so(): void
    {
        $this->supabase(['POST /rpc/moderation_mirrors' => self::error()]);

        $this->signedIn()->get('/moderation/mirrors')->assertOk()->assertSee('The mirrors could not be read.');
    }

    public function test_adding_trims_the_fields_and_drops_cached_pages(): void
    {
        $this->supabase(['POST /rpc/moderation_add_mirror' => self::reply('added')]);

        $this->assertFalse($this->cachedPagesSurvive(function () {
            $this->signedIn()->from('/moderation/mirrors')
                ->post('/moderation/mirrors/add', ['id' => ' scary ', 'name' => ' Scary ', 'base_url' => ' https://scary.example.org/ ', 'public_key' => ' '.self::KEY.' '])
                ->assertRedirect('/moderation/mirrors')
                ->assertSessionHas('mirrorsState.ok', true);
        }));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderation_add_mirror')
            && $r->data() === ['p_id' => 'scary', 'p_name' => 'Scary', 'p_base_url' => 'https://scary.example.org', 'p_public_key' => self::KEY]
            && $r->hasHeader('Authorization', 'Bearer access'));
    }

    public function test_a_refused_add_says_why_keeps_what_was_typed_and_keeps_cached_pages(): void
    {
        $this->supabase(['POST /rpc/moderation_add_mirror' => self::reply('id_taken')]);

        $this->assertTrue($this->cachedPagesSurvive(function () {
            $this->signedIn()->from('/moderation/mirrors')
                ->post('/moderation/mirrors/add', ['id' => 'jj-darkstars', 'name' => 'X', 'base_url' => 'https://x.test', 'public_key' => self::KEY])
                ->assertSessionHas('mirrorsState', ['ok' => false, 'message' => 'Another mirror already has that ID.'])
                ->assertSessionHasInput('name', 'X');
        }));
    }

    public function test_removing_and_switching_off_both_count_as_done(): void
    {
        $this->supabase(['POST /rpc/moderation_remove_mirror' => self::reply('switched_off')]);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/remove', ['mirror_id' => 'jj-darkstars'])
            ->assertRedirect('/moderation/mirrors')
            ->assertSessionHas('mirrorsState.ok', true)
            ->assertSessionHas('mirrorsState.message', 'Uploads went through this mirror, so the hub keeps its record and switched it off instead. It gets no downloads or uploads.');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderation_remove_mirror') && $r->data() === ['p_id' => 'jj-darkstars']);
    }

    public function test_switching_on_sends_the_id(): void
    {
        $this->supabase(['POST /rpc/moderation_enable_mirror' => self::reply('switched_on')]);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/enable', ['mirror_id' => 'off'])
            ->assertSessionHas('mirrorsState.ok', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderation_enable_mirror') && $r->data() === ['p_id' => 'off']);
    }

    public function test_a_database_error_is_a_plain_failure(): void
    {
        $this->supabase(['POST /rpc/moderation_remove_mirror' => self::error()]);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/remove', ['mirror_id' => 'jj-darkstars'])
            ->assertSessionHas('mirrorsState', ['ok' => false, 'message' => 'It could not be removed just now. Try again.']);
    }

    private function insertRequest(string $key = self::KEY): void
    {
        DB::table('mirror_request')->insert([
            'public_key' => $key, 'mirror_id' => 'new-mirror', 'name' => 'New Mirror', 'base_url' => MirrorRequestsTest::URL,
            'requested_by' => MirrorRequestsTest::USER, 'requested_at' => '2026-11-23 10:00:00', 'checked_at' => '2026-11-23 10:00:00',
        ]);
    }

    public function test_the_page_lists_requests_with_who_asked(): void
    {
        $this->supabase(['POST /rpc/moderation_mirrors' => self::reply(['seq' => 1, 'mirrors' => [self::mirror()]])]);
        $this->insertRequest();
        DB::table('profile')->insert(['user_id' => MirrorRequestsTest::USER, 'display_name' => 'Operator', 'created_at' => now(), 'updated_at' => now()]);

        $this->signedIn()->get('/moderation/mirrors')
            ->assertOk()
            ->assertSeeInOrder(['Requests', 'New Mirror', 'new-mirror', MirrorRequestsTest::URL, self::KEY, 'Operator', 'Approve New Mirror', 'Reject New Mirror'])
            ->assertSee('Check the key of jj.darkstars.co.uk');
    }

    public function test_the_page_says_when_there_are_no_requests(): void
    {
        $this->supabase(['POST /rpc/moderation_mirrors' => self::reply(['seq' => 1, 'mirrors' => []])]);

        $this->signedIn()->get('/moderation/mirrors')->assertSee('No mirrors are waiting to be added.');
    }

    public function test_approving_proves_the_key_then_adds_the_mirror(): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $key = B64::encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        $this->app->instance(Identity::class, MirrorRequestsTest::identity());
        $this->supabase([
            'identity.php' => MirrorRequestsTest::identityAnswer($secret, 'new-mirror'),
            'POST /rpc/moderation_add_mirror' => self::reply('added'),
        ]);
        $this->insertRequest($key);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/requests/approve', ['public_key' => $key])
            ->assertRedirect('/moderation/mirrors')
            ->assertSessionHas('mirrorsState.ok', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderation_add_mirror') && $r['p_public_key'] === $key);
        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_rejecting_removes_the_request(): void
    {
        $this->supabase();
        $this->insertRequest();

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/requests/reject', ['public_key' => self::KEY])
            ->assertSessionHas('mirrorsState', ['ok' => true, 'message' => 'Request rejected.']);

        $this->assertSame(0, DB::table('mirror_request')->count());
    }

    public function test_checking_a_key_reports_the_result(): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->app->instance(Identity::class, MirrorRequestsTest::identity());
        $mirror = self::mirror(['base_url' => MirrorRequestsTest::URL, 'public_key' => B64::encode(sodium_crypto_sign_publickey_from_secretkey($secret))]);
        $this->supabase([
            'POST /rpc/moderation_mirrors' => self::reply(['seq' => 1, 'mirrors' => [$mirror]]),
            'identity.php' => MirrorRequestsTest::identityAnswer($secret, 'jj-darkstars'),
        ]);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/check', ['mirror_id' => 'jj-darkstars'])
            ->assertSessionHas('mirrorsState', ['ok' => true, 'message' => 'The mirror proved it holds the key the hub has for it.']);

        $this->signedIn()->from('/moderation/mirrors')
            ->post('/moderation/mirrors/check', ['mirror_id' => 'gone'])
            ->assertSessionHas('mirrorsState.ok', false);
    }
}
