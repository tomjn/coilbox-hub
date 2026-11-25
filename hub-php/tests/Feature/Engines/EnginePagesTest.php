<?php

namespace Tests\Feature\Engines;

use App\Domain\Engines\Engines;
use App\Http\Controllers\Moderation\ArchivesController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** The unlisted engine pages: the list, one build, the uploader's note and withdrawal. */
class EnginePagesTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const STRANGER = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    private function engine(string $version = '2026.09.03', string $platform = 'amd64-linux', string $uploader = self::MODERATOR, ?string $mirror = 'jj-darkstars', string $note = 'Test build.', string $state = 'listed'): string
    {
        $id = (string) Str::uuid();
        $filename = 'recoil_'.$version.'_'.$platform.'.7z';
        DB::table('archive')->insert([
            'id' => $id, 'kind' => 'engine', 'internal_name' => 'recoil', 'version' => $version, 'platform' => $platform,
            'filename' => $filename, 'filename_lower' => strtolower($filename), 'sha256' => hash('sha256', $filename),
            'size' => 43, 'uploader' => $uploader, 'state' => $state, 'description' => $note,
            'listed_at' => '2026-11-18 10:00:00.000000', 'created_at' => '2026-11-18 10:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
        if ($mirror !== null) {
            DB::table('archive_mirror')->insert(['archive_id' => $id, 'mirror_id' => $mirror, 'first_seen_at' => '2026-11-18 10:00:00.000000', 'last_seen_at' => '2026-11-18 10:00:00.000000']);
        }

        return $id;
    }

    public function test_the_list_shows_the_warning_and_engines_by_platform(): void
    {
        $this->supabase(moderator: false);
        $this->engine('2026.09.03', 'amd64-linux');
        $this->engine('2026.09.04', 'arm64-macos', mirror: null);

        $this->get('/engines')->assertOk()
            ->assertSee(Engines::WARNING)
            ->assertSeeInOrder(['amd64-linux', '2026.09.03', 'arm64-macos', '2026.09.04'])
            ->assertSee('/engines/2026.09.03/amd64-linux', false)
            ->assertSee('43 bytes');
    }

    public function test_the_list_and_page_are_noindex_and_not_in_the_sitemap(): void
    {
        config(['hub.items_store' => 'mariadb']);
        $this->supabase(moderator: false);
        $this->engine();

        $this->get('/engines')->assertSee('<meta name="robots" content="noindex">', false);
        $this->get('/engines/2026.09.03/amd64-linux')->assertSee('<meta name="robots" content="noindex">', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/engines');
    }

    public function test_the_nav_has_no_engines_link(): void
    {
        $this->supabase(moderator: false);

        $this->get('/engines')->assertOk()->assertDontSee('href="/engines"', false);
    }

    public function test_a_version_with_dots_hyphens_and_plus_works(): void
    {
        $this->supabase(moderator: false);
        $this->engine('2026.09.01-137-g15f18d5+mac', 'arm64-macos');

        $path = Engines::pagePath('2026.09.01-137-g15f18d5+mac', 'arm64-macos');
        $this->assertSame('/engines/2026.09.01-137-g15f18d5%2Bmac/arm64-macos', $path);
        $this->get($path)->assertOk()->assertSee('2026.09.01-137-g15f18d5+mac');
        $this->get('/engines')->assertSee($path, false);
    }

    public function test_the_page_shows_sha256_and_a_link_per_healthy_mirror(): void
    {
        $this->supabase(moderator: false);
        $this->engine();

        $this->get('/engines/2026.09.03/amd64-linux')->assertOk()
            ->assertSee(hash('sha256', 'recoil_2026.09.03_amd64-linux.7z'))
            ->assertSee('https://mirror.test/engines/recoil_2026.09.03_amd64-linux.7z', false)
            ->assertSee(Engines::WARNING)
            ->assertDontSee('No mirror holds this build right now.');
    }

    public function test_an_engine_with_no_healthy_mirror_says_none_hold_it(): void
    {
        $this->supabase(moderator: false);
        $this->engine(mirror: null);

        $this->get('/engines/2026.09.03/amd64-linux')->assertOk()->assertSee('No mirror holds this build right now.');
    }

    public function test_a_removed_or_unknown_engine_is_404(): void
    {
        $this->supabase(moderator: false);
        $this->engine(state: 'removed');

        $this->get('/engines/2026.09.03/amd64-linux')->assertNotFound();
        $this->get('/engines/9.9/amd64-linux')->assertNotFound();
        $this->get('/engines/2026.09.03/beos')->assertNotFound();
    }

    public function test_a_note_with_html_and_line_breaks_shows_as_text(): void
    {
        $this->supabase(moderator: false);
        $this->engine(note: "<b>x</b>\nsecond line");

        $this->get('/engines/2026.09.03/amd64-linux')->assertOk()
            ->assertSee('&lt;b&gt;x&lt;/b&gt;', false)
            ->assertDontSee('<b>x</b>', false)
            ->assertSee('whitespace-pre-line', false);
    }

    public function test_the_uploader_edits_the_note_and_the_catalogue_changes(): void
    {
        $this->supabase(moderator: false);
        $this->engine();
        $before = (int) DB::table('catalogue_state')->value('changes');

        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/note', ['note' => '  A new note.  '])
            ->assertRedirect('/engines/2026.09.03/amd64-linux');

        $this->assertSame('A new note.', DB::table('archive')->value('description'));
        $this->assertSame($before + 1, (int) DB::table('catalogue_state')->value('changes'));
        $this->signedIn()->get('/engines/2026.09.03/amd64-linux')->assertSee('A new note.');
    }

    public function test_a_bad_note_is_refused_and_changes_nothing(): void
    {
        $this->supabase(moderator: false);
        $this->engine();
        $before = (int) DB::table('catalogue_state')->value('changes');

        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/note', ['note' => '   '])->assertRedirect();
        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/note', ['note' => str_repeat('a', Engines::NOTE_MAX + 1)])->assertRedirect();

        $this->assertSame('Test build.', DB::table('archive')->value('description'));
        $this->assertSame($before, (int) DB::table('catalogue_state')->value('changes'));
    }

    public function test_a_stranger_cannot_edit_or_withdraw(): void
    {
        $this->supabase(moderator: false);
        $this->engine(uploader: self::STRANGER);

        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/note', ['note' => 'Hijacked.']);
        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/withdraw');
        $this->post('/engines/2026.09.03/amd64-linux/note', ['note' => 'Hijacked.']);

        $archive = DB::table('archive')->first();
        $this->assertSame('Test build.', $archive->description);
        $this->assertSame('listed', $archive->state);
    }

    public function test_the_uploader_withdraws_and_the_engine_is_gone(): void
    {
        $this->supabase(moderator: false);
        $this->engine();

        $this->signedIn()->post('/engines/2026.09.03/amd64-linux/withdraw')->assertRedirect('/engines');

        $archive = DB::table('archive')->first();
        $this->assertSame('removed', $archive->state);
        $this->assertSame('withdrawn', $archive->removal_reason);
        $this->get('/engines/2026.09.03/amd64-linux')->assertNotFound();
    }

    public function test_only_the_uploader_sees_the_edit_form_and_moderators_see_the_remove_form(): void
    {
        $this->engine(uploader: self::STRANGER);

        $this->supabase(moderator: false);
        $this->signedIn()->get('/engines/2026.09.03/amd64-linux')->assertOk()
            ->assertDontSee('/note"', false)->assertDontSee('/moderation/archives/remove', false);

        $this->supabase(moderator: true);
        $this->signedIn()->get('/engines/2026.09.03/amd64-linux')->assertOk()
            ->assertSee('/moderation/archives/remove', false)->assertSee('/note"', false);
    }

    public function test_the_page_has_an_edit_note_heading_for_the_uploader(): void
    {
        $this->supabase(moderator: false);
        $this->engine();

        $this->signedIn()->get('/engines/2026.09.03/amd64-linux')->assertSee('Edit note');
    }

    public function test_a_moderator_removing_an_engine_from_its_page_lands_on_the_list_with_a_confirmation(): void
    {
        $this->supabase();
        $this->engine(uploader: self::STRANGER);

        $this->signedIn()->from('/engines/2026.09.03/amd64-linux')
            ->post('/moderation/archives/remove', ['archive_id' => DB::table('archive')->value('id'), 'reason' => 'broken', 'return' => '/engines'])
            ->assertRedirect('/engines');

        $this->assertSame('removed', DB::table('archive')->value('state'));
        $this->signedIn()->get('/engines')->assertOk()->assertSee('Removed. The hub stopped linking it');
    }

    public function test_a_return_that_is_not_a_local_path_falls_back_to_going_back(): void
    {
        $this->supabase();
        $id = $this->engine(uploader: self::STRANGER);

        foreach (['//evil.test', 'https://evil.test', '/\\evil.test', 'evil'] as $return) {
            $this->signedIn()->from('/moderation/archives')
                ->post('/moderation/archives/remove', ['archive_id' => $id, 'reason' => '', 'return' => $return])
                ->assertRedirect('/moderation/archives');
        }
    }

    public function test_a_return_with_a_trailing_newline_is_not_a_local_path(): void
    {
        // The request middleware trims input, so this calls the check directly.
        $check = new \ReflectionMethod(ArchivesController::class, 'isLocalPath');

        $this->assertTrue($check->invoke(new ArchivesController, '/engines'));
        $this->assertFalse($check->invoke(new ArchivesController, "/engines\n"));
    }

    public function test_the_moderation_removal_without_a_return_still_goes_back(): void
    {
        $this->supabase();
        $id = $this->engine(uploader: self::STRANGER);

        $this->signedIn()->from('/moderation/archives?q=recoil')
            ->post('/moderation/archives/remove', ['archive_id' => $id, 'reason' => 'broken'])
            ->assertRedirect('/moderation/archives?q=recoil');
        $this->assertSame('removed', DB::table('archive')->value('state'));
    }

    public function test_moderation_archives_links_an_engine_to_its_page(): void
    {
        $this->supabase(['/rest/v1/profile' => self::reply([['user_id' => self::MODERATOR, 'display_name' => 'Uploader']])]);
        $this->engine('2026.09.01-137-g15f18d5+mac', 'arm64-macos');

        $this->signedIn()->get('/moderation/archives')->assertOk()
            ->assertSee('href="/engines/2026.09.01-137-g15f18d5%2Bmac/arm64-macos"', false)
            ->assertSee(', platform arm64-macos');
    }
}
