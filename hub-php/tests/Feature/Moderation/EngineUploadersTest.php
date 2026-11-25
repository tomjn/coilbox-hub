<?php

namespace Tests\Feature\Moderation;

use App\Domain\Engines\EngineUploaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/** `/moderation/engines`, who may upload engine builds. */
class EngineUploadersTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const OTHER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private static function profile(string $id, string $name): void
    {
        DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_signed_out_visitor_and_a_stranger_get_404_and_write_nothing(): void
    {
        $this->supabase(moderator: false);

        $this->get('/moderation/engines')->assertNotFound();
        $this->signedIn()->get('/moderation/engines')->assertNotFound();
        $this->signedIn()->post('/moderation/engines/grant', ['user_id' => self::USER]);
        EngineUploaders::grant(self::OTHER, self::MODERATOR);
        $this->signedIn()->post('/moderation/engines/revoke', ['user_id' => self::OTHER]);

        $this->assertFalse(EngineUploaders::has(self::USER));
        $this->assertTrue(EngineUploaders::has(self::OTHER));
        $this->assertSame([], $this->sentWrites());
    }

    public function test_the_page_lists_grants_with_names_and_who_granted(): void
    {
        $this->supabase();
        self::profile(self::USER, 'Ada');
        self::profile(self::MODERATOR, 'Moddy');
        EngineUploaders::grant(self::USER, self::MODERATOR);
        EngineUploaders::grant(self::OTHER, self::MODERATOR);

        $page = $this->signedIn()->get('/moderation/engines');

        $page->assertOk()->assertSeeText('Engine uploaders')
            ->assertSeeText('Ada')->assertSeeText('Moddy')
            ->assertSee(self::USER)
            ->assertSee(self::OTHER);
        $page->assertSee('name="user_id" value="'.self::USER.'"', false);
    }

    public function test_searching_lists_matching_accounts_with_a_grant_button(): void
    {
        $this->supabase(['POST /rpc/moderator_accounts' => self::reply([
            ['id' => self::USER, 'display_name' => 'Ada', 'created_at' => '2026-11-01T10:00:00+00:00', 'capabilities' => []],
            ['id' => self::OTHER, 'display_name' => 'Bob', 'created_at' => '2026-11-02T10:00:00+00:00', 'capabilities' => []],
        ])]);
        EngineUploaders::grant(self::OTHER, self::MODERATOR);

        $page = $this->signedIn()->get('/moderation/engines?q=a');

        $page->assertOk()->assertSeeText('Ada')->assertSeeText('Grant Ada');
        $page->assertDontSeeText('Grant Bob');
        $this->assertTrue(collect($this->sent)->contains(fn ($r) => str_contains($r->url(), 'moderator_accounts') && str_contains($r->body(), '"a"')));
    }

    public function test_granting_adds_a_row_and_says_so(): void
    {
        $this->supabase();

        $this->signedIn()->post('/moderation/engines/grant', ['user_id' => self::USER])
            ->assertRedirect()
            ->assertSessionHas('engineUploadersState.ok', true);

        $this->assertTrue(EngineUploaders::has(self::USER));
        $this->assertSame(self::MODERATOR, DB::table('engine_uploader')->value('granted_by'));
        $this->signedIn()->get('/moderation/engines')->assertSeeText('can now upload engines');
    }

    public function test_granting_twice_says_already_granted(): void
    {
        $this->supabase();
        EngineUploaders::grant(self::USER, self::MODERATOR);

        $this->signedIn()->post('/moderation/engines/grant', ['user_id' => self::USER])
            ->assertSessionHas('engineUploadersState.ok', false)
            ->assertSessionHas('engineUploadersState.message', fn ($m) => str_contains($m, 'already'));

        $this->assertSame(1, DB::table('engine_uploader')->count());
    }

    public function test_granting_needs_a_user_id(): void
    {
        $this->supabase();

        $this->signedIn()->post('/moderation/engines/grant', ['user_id' => ''])
            ->assertSessionHas('engineUploadersState.ok', false);

        $this->assertSame(0, DB::table('engine_uploader')->count());
    }

    public function test_revoking_removes_the_row_and_keeps_their_engines(): void
    {
        $this->supabase();
        EngineUploaders::grant(self::USER, self::MODERATOR);
        DB::table('archive')->insert($this->engineArchive());

        $this->signedIn()->post('/moderation/engines/revoke', ['user_id' => self::USER])
            ->assertSessionHas('engineUploadersState.ok', true);

        $this->assertFalse(EngineUploaders::has(self::USER));
        $this->assertSame(1, DB::table('archive')->count());
    }

    public function test_revoking_someone_with_no_grant_says_so(): void
    {
        $this->supabase();

        $this->signedIn()->post('/moderation/engines/revoke', ['user_id' => self::USER])
            ->assertSessionHas('engineUploadersState.ok', false);
    }

    public function test_the_nav_links_the_page(): void
    {
        $this->supabase();

        $this->signedIn()->get('/moderation/engines')
            ->assertSee('href="/moderation/engines"', false)
            ->assertSeeText('Engines');
    }

    /** @return array<string, mixed> */
    private function engineArchive(): array
    {
        $columns = DB::getSchemaBuilder()->getColumns('archive');
        $row = [];
        foreach ($columns as $c) {
            if ($c['nullable'] || $c['auto_increment'] || $c['default'] !== null) {
                continue;
            }
            $row[$c['name']] = match (true) {
                str_contains($c['type_name'], 'int') => 1,
                str_contains($c['type_name'], 'time') || str_contains($c['type_name'], 'date') => now(),
                default => 'x',
            };
        }
        $row['uploader'] = self::USER;

        return $row;
    }
}
