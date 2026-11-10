<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The build tree page, with PostgREST faked. */
class TreePageTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    /**
     * A game with two sides. `cmd` is the start unit. It builds a factory and a
     * wall. The factory builds a tank and a second factory, and the second
     * factory builds the tank too, so the tank is shared by two builders.
     *
     * @var list<array<string, mixed>>
     */
    private const UNITS = [
        ['unit_name' => 'cmd', 'full_name' => 'Commander', 'faction_key' => 'a', 'build_options' => ['fac', 'wall', 'fac2'], 'stats' => null],
        ['unit_name' => 'fac', 'full_name' => 'Factory', 'faction_key' => 'a', 'build_options' => ['tank', 'fac2'], 'stats' => null],
        ['unit_name' => 'fac2', 'full_name' => 'Second factory', 'faction_key' => 'a', 'build_options' => ['tank'], 'stats' => null],
        ['unit_name' => 'tank', 'full_name' => 'Tank', 'faction_key' => 'a', 'build_options' => [], 'stats' => ['weapons' => [['name' => 'gun']]]],
        ['unit_name' => 'wall', 'full_name' => 'Wall', 'faction_key' => 'a', 'build_options' => [], 'stats' => null],
        ['unit_name' => 'bcmd', 'full_name' => 'Other commander', 'faction_key' => 'b', 'build_options' => [], 'stats' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'app.url' => 'https://hub.test',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->urls[] = urldecode($request->url());
            // Not parse_str, which turns the dots in `game.shortname` into underscores.
            $query = [];
            foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $pair) {
                [$key, $value] = [...explode('=', urldecode($pair), 2), ''];
                $query[$key] = $value;
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $select = (string) ($query['select'] ?? '');
            $paged = ($query['offset'] ?? '0') !== '0';
            $known = ($query['shortname'] ?? 'eq.tg') === 'eq.tg';

            return match (true) {
                $path === '/rest/v1/game' && str_contains($select, 'display_name') => Http::response($known ? [[
                    'shortname' => 'tg', 'display_name' => 'Test Game', 'description' => null, 'links' => [],
                    'conquest_factions' => [], 'game_faction' => [['key' => 'a', 'name' => 'Alpha', 'logo_path' => null], ['key' => 'b', 'name' => 'Beta', 'logo_path' => null]],
                    'game_version' => [['version' => 'v2', 'last_seen_at' => '2026-02-01']],
                ]] : []),
                $path === '/rest/v1/game_browse' && isset($query['shortname']) => Http::response($known ? [['faction_count' => 2, 'unit_count' => 6, 'item_count' => 0]] : []),
                $path === '/rest/v1/game' && $select === 'start_units' => Http::response([['start_units' => ['cmd', 'bcmd']]]),
                $path === '/rest/v1/game_faction' => Http::response([['key' => 'a', 'name' => 'Alpha'], ['key' => 'b', 'name' => 'Beta']]),
                $path === '/rest/v1/game_unit' => Http::response($paged ? [] : self::unitRows(isset($query['game_unit_revision.version']))),
                default => Http::response([]),
            };
        });
    }

    /** @return list<array<string, mixed>> */
    private static function unitRows(bool $revision): array
    {
        return array_map(function (array $unit) use ($revision) {
            $row = $unit + ['morph_targets' => [], 'removed_at' => null];
            if ($revision) {
                $row['game_unit_revision'] = [['version' => 'v1', 'full_name' => $unit['full_name'].' (v1)', 'faction_key' => $unit['faction_key'],
                    'build_options' => $unit['build_options'], 'morph_targets' => [], 'stats' => $unit['stats']]];
            }

            return $row;
        }, self::UNITS);
    }

    public function test_it_draws_the_first_faction_from_its_start_unit(): void
    {
        $html = $this->get('/games/tg/tree')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Test Game build tree', $html);
        $this->assertStringContainsString('aria-label="Commander"', $html);
        $this->assertStringContainsString('5 units', $html);
        $this->assertStringNotContainsString('Other commander', $html);
        $this->assertStringContainsString('Facts as of the latest reports.', $html);
        $this->assertStringContainsString('href="/games/tg/units/tank"', $html);
    }

    public function test_the_faction_toggles_link_to_the_tree_with_the_faction_chosen(): void
    {
        $html = $this->get('/games/tg/tree')->getContent();

        $this->assertStringContainsString('href="/games/tg/tree?faction=b"', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    public function test_a_unit_two_builders_make_opens_under_the_first_only(): void
    {
        $html = $this->get('/games/tg/tree')->getContent();

        // The commander claims fac and fac2 together. fac2 opens under the
        // commander and is a plain link under fac.
        $this->assertSame(3, substr_count($html, '<details'));
        $this->assertSame(1, preg_match_all('#href="/games/tg/units/fac2" class="[^"]*text-neutral-300#', $html));
        $this->assertSame(1, preg_match_all('#href="/games/tg/units/fac2" class="[^"]*text-neutral-400#', $html));
    }

    public function test_a_faction_in_the_query_scopes_the_walk(): void
    {
        $html = $this->get('/games/tg/tree?faction=b')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Other commander"', $html);
        $this->assertStringNotContainsString('href="/games/tg/units/tank"', $html);
    }

    public function test_an_unknown_faction_draws_the_empty_message(): void
    {
        $this->get('/games/tg/tree?faction=nope')->assertOk()->assertSee('reported this game', false);
    }

    public function test_a_release_in_the_query_reads_its_revisions_and_is_named(): void
    {
        $html = $this->get('/games/tg/tree?v=v1&faction=a')->assertOk()->getContent();

        $this->assertStringContainsString('Facts as of release v1.', $html);
        $this->assertStringContainsString('Commander (v1)', $html);
        $this->assertNotEmpty(array_filter($this->urls, fn ($url) => str_contains($url, 'game_unit_revision.version=eq.v1')));
    }

    public function test_the_toggle_links_keep_the_release(): void
    {
        $html = $this->get('/games/tg/tree?v=v1')->getContent();

        $this->assertStringContainsString('href="/games/tg/tree?v=v1&amp;faction=b"', $html);
    }

    public function test_a_query_without_the_filter_keys_changes_nothing(): void
    {
        $this->get('/games/tg/tree?utm=x&v')->assertOk();
        $plain = $this->get('/games/tg/tree?utm=x')->getContent();

        $this->assertStringContainsString('Facts as of the latest reports.', $plain);
    }

    public function test_a_repeated_key_reads_its_first_value(): void
    {
        $html = $this->get('/games/tg/tree?faction=b&faction=a')->getContent();

        $this->assertStringContainsString('aria-label="Other commander"', $html);
    }

    public function test_an_unknown_game_is_a_404(): void
    {
        $this->get('/games/nope/tree')->assertNotFound();
    }
}
