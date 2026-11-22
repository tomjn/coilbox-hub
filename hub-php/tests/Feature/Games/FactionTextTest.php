<?php

namespace Tests\Feature\Games;

use App\Domain\Games\GameEdits;
use App\Domain\Games\GameFacts;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\TestCase;

/**
 * A faction's tagline and Markdown description: written by the game's owner
 * or a moderator, shown on the game and units pages, and served to Coilbox.
 */
class FactionTextTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalCatalogue();
        $this->seedCatalogue();
        $this->fakeSupabaseRemainder(['https://sb.test/auth/v1/user' => Http::response(['id' => self::OWNER])]);
        Http::fake(['https://sb.test/*' => Http::response([])]);
    }

    private function edit(string $userId, string $key, string $tagline, string $description): array
    {
        return GameEdits::editFactionText(Supabase::forToken('token', $userId), $userId, 'BA', $key, $tagline, $description);
    }

    private function arm(): object
    {
        return DB::table('game_faction')->where('id', 1)->first();
    }

    public function test_the_owner_saves_a_tagline_and_a_description(): void
    {
        $answer = $this->edit(self::OWNER, 'arm', "  Strength\nthrough   unity ", "# Arm\r\n\r\nThe **Arm**.\r\n");

        $this->assertSame(['ok' => true, 'message' => 'Faction saved.'], $answer);
        $this->assertSame('Strength through unity', $this->arm()->tagline);
        $this->assertSame("# Arm\n\nThe **Arm**.", $this->arm()->description);
        $this->assertNull(DB::table('game_faction')->where('id', 2)->value('tagline'));
    }

    public function test_blank_clears_both(): void
    {
        $this->edit(self::OWNER, 'arm', 'Tag', 'Words');
        $this->edit(self::OWNER, 'arm', ' ', "\n");

        $this->assertNull($this->arm()->tagline);
        $this->assertNull($this->arm()->description);
    }

    public function test_long_text_is_cut_to_the_limits(): void
    {
        $this->edit(self::OWNER, 'arm', str_repeat('t', 300), str_repeat('d', 5000));

        $this->assertSame(GameEdits::TAGLINE_MAX, mb_strlen($this->arm()->tagline));
        $this->assertSame(GameEdits::FACTION_DESCRIPTION_MAX, mb_strlen($this->arm()->description));
    }

    public function test_a_stranger_and_an_unknown_faction_are_refused(): void
    {
        $this->assertFalse($this->edit(self::STRANGER, 'arm', 'Mine now', '')['ok']);
        $this->assertFalse($this->edit(self::OWNER, 'nobody', 'Tag', '')['ok']);
        $this->assertNull($this->arm()->tagline);
    }

    public function test_a_new_facts_report_keeps_the_text(): void
    {
        $this->edit(self::OWNER, 'arm', 'Tag', 'Words');

        $this->withHeader('Authorization', 'Bearer token')->postJson('/api/v1/games/facts', [
            'format' => GameFacts::FORMAT, 'version' => 1, 'shortname' => 'BA', 'release' => '3.0',
            'factions' => [['key' => 'arm', 'name' => 'Arm Renamed'], ['key' => 'core', 'name' => 'Core']], 'units' => [],
        ])->assertOk();

        $this->assertSame('Arm Renamed', $this->arm()->name);
        $this->assertSame('Tag', $this->arm()->tagline);
        $this->assertSame('Words', $this->arm()->description);
    }

    public function test_the_api_serves_every_side_with_its_markdown(): void
    {
        $this->edit(self::OWNER, 'arm', 'Tag', '**Bold**');

        $this->getJson('/api/v1/games/BA/factions')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson([
                'format' => 'coilbox-hub-factions',
                'version' => 1,
                'shortname' => 'BA',
                'factions' => [
                    ['key' => 'arm', 'name' => 'Arm', 'tagline' => 'Tag', 'description' => '**Bold**'],
                    ['key' => 'core', 'name' => 'Core', 'tagline' => null, 'description' => null],
                    ['key' => 'random', 'name' => 'Random', 'tagline' => null, 'description' => null],
                ],
            ]);
    }

    public function test_the_api_does_not_find_an_unknown_or_hidden_game(): void
    {
        $this->getJson('/api/v1/games/NOPE/factions')->assertNotFound();
        $this->getJson('/api/v1/games/HID/factions')->assertNotFound();
    }

    public function test_the_pages_show_the_tagline_and_the_rendered_description(): void
    {
        $this->edit(self::OWNER, 'arm', 'Strength through unity', "## History\n\n- one\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))");

        $this->get('/games/BA')->assertOk()->assertSee('Strength through unity');

        $this->get('/games/BA/units?faction=arm')->assertOk()
            ->assertSee('<h2>History</h2>', false)
            ->assertSee('<li>one</li>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('javascript:alert', false);

        $this->get('/games/BA/units?faction=core')->assertOk()->assertDontSee('faction-about');
    }
}
