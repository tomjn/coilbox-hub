<?php

namespace Tests\Feature\Moderation;

use App\Support\HubCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** `/moderation/mirrors`, listing the hub's mirrors, adding one and removing one. */
class MirrorsTest extends ModerationTestCase
{
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

        $this->assertSame([], $this->sentWrites());
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
}
