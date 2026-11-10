<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The release changes page, with PostgREST faked. */
class ChangesPageTest extends TestCase
{
    /** @var list<string> the release names the fake game holds, newest first */
    private array $releases = ['v3', 'v2', 'v1'];

    /** @var list<string> the releases the hub does not know were reported in full */
    private array $partial = [];

    /** @var list<array<string, mixed>> */
    private array $revisions = [];

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'app.url' => 'https://hub.test',
        ]);
        $this->revisions = [
            $this->revision('v1', 'old', 'Old unit', 'a', ['gun']),
            $this->revision('v1', 'same', 'Same unit', 'a', []),
            $this->revision('v1', 'moved', 'Moved unit', 'a', ['gun'], ['hp' => 100]),
            $this->revision('v2', 'same', 'Same unit', 'a', []),
            $this->revision('v2', 'moved', 'Moved unit', 'b', ['gun', 'gun2'], ['hp' => 150]),
            $this->revision('v2', 'new', 'New unit', 'a', []),
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->urls[] = urldecode($request->url());
            $query = [];
            foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $pair) {
                [$key, $value] = [...explode('=', urldecode($pair), 2), ''];
                $query[$key] = $value;
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $select = $query['select'] ?? '';
            $known = ($query['shortname'] ?? $query['game.shortname'] ?? 'eq.tg') === 'eq.tg';
            $paged = ($query['offset'] ?? '0') !== '0';

            return match (true) {
                $path === '/rest/v1/game' => Http::response($known ? [[
                    'shortname' => 'tg', 'display_name' => 'Test Game', 'description' => null, 'links' => [], 'conquest_factions' => [],
                    'game_faction' => [], 'game_version' => [],
                ]] : []),
                $path === '/rest/v1/game_browse' && isset($query['shortname']) => Http::response($known ? [['faction_count' => 2, 'unit_count' => 3, 'item_count' => 0]] : []),
                $path === '/rest/v1/game_faction' => Http::response([['key' => 'a', 'name' => 'Alpha'], ['key' => 'b', 'name' => 'Beta']]),
                $path === '/rest/v1/game_version' && str_contains($select, 'reported_in_full') => Http::response(array_map(
                    fn (string $version) => ['version' => $version, 'reported_in_full' => ! in_array($version, $this->partial, true)],
                    $this->releases,
                )),
                $path === '/rest/v1/game_version' => Http::response(array_map(
                    fn (string $version) => ['version' => $version, 'last_seen_at' => '2026-01-01'],
                    $this->releases,
                )),
                $path === '/rest/v1/game_unit_revision' => Http::response($paged ? [] : $this->revisions),
                $path === '/rest/v1/game_unit' => Http::response($paged ? [] : [
                    ['unit_name' => 'gun', 'full_name' => 'Gun'],
                    ['unit_name' => 'gun2', 'full_name' => 'Big gun'],
                ]),
                default => Http::response([]),
            };
        });
    }

    /** @return array<string, mixed> */
    private function revision(string $version, string $unit, string $name, string $faction, array $builds, array $stats = []): array
    {
        return [
            'id' => count($this->revisions ?? []) + 1, 'version' => $version, 'full_name' => $name, 'faction_key' => $faction,
            'build_options' => $builds, 'stats' => $stats, 'game_unit' => ['unit_name' => $unit, 'game' => ['shortname' => 'tg']],
        ];
    }

    public function test_the_newest_two_releases_are_compared_by_default(): void
    {
        $html = $this->get('/games/tg/changes')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Test Game release changes', $html);
        $this->assertStringContainsString('From v2 to v3:', $html);
        $this->assertMatchesRegularExpression('#<option value="v2" selected="">v2</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="v3" selected="">v3</option>#', $html);
    }

    public function test_it_counts_and_lists_what_changed(): void
    {
        $html = $this->get('/games/tg/changes?from=v1&to=v2')->assertOk()->getContent();

        $this->assertStringContainsString('From v1 to v2: 1 added, 1 removed, 1 changed, 1 unchanged.', $html);
        $this->assertStringContainsString('Added in v2 (1)', $html);
        $this->assertStringContainsString('New unit', $html);
        $this->assertStringContainsString('Removed in v2 (1)', $html);
        $this->assertStringContainsString('Old unit', $html);
        $this->assertStringContainsString('Changed (1)', $html);
    }

    public function test_a_changed_unit_shows_its_faction_stats_and_builds(): void
    {
        $html = $this->get('/games/tg/changes?from=v1&to=v2')->getContent();

        $this->assertStringContainsString('Alpha to Beta', $html);
        $this->assertStringContainsString('Can now build', $html);
        $this->assertStringContainsString('href="/games/tg/units/gun2"', $html);
        $this->assertStringContainsString('Big gun', $html);
        $this->assertStringContainsString('href="/games/tg/units/moved/compare?left=v1&amp;right=v2"', $html);
    }

    public function test_a_name_the_hub_does_not_hold_gets_a_note_and_a_default(): void
    {
        $html = $this->get('/games/tg/changes?from=nope&to=v2')->assertOk()->getContent();

        $this->assertStringContainsString('The hub holds no release called &quot;nope&quot;.', $html);
        $this->assertStringContainsString('From v1 to v2:', $html);
    }

    public function test_a_pair_the_reader_chose_is_kept_with_a_note_when_from_is_newer(): void
    {
        $html = $this->get('/games/tg/changes?from=v3&to=v1')->getContent();

        $this->assertStringContainsString('v3 was reported more recently than v1', $html);
    }

    public function test_the_same_release_twice_asks_for_two(): void
    {
        $this->get('/games/tg/changes?from=v2&to=v2')->assertOk()
            ->assertSee('Both pickers name v2. Choose two different releases to see what changed.');
    }

    public function test_a_release_not_reported_in_full_is_not_listed_as_added(): void
    {
        $this->partial = ['v1'];

        $html = $this->get('/games/tg/changes?from=v1&to=v2')->assertOk()->getContent();

        $this->assertStringContainsString('added not known, 1 removed', $html);
        $this->assertStringContainsString('Added units are not listed.', $html);
        $this->assertStringNotContainsString('New unit', $html);
    }

    public function test_one_release_has_nothing_to_compare(): void
    {
        $this->releases = ['v1'];

        $this->get('/games/tg/changes')->assertOk()
            ->assertSee('The hub holds one release of this game, v1, so there is nothing to compare it with yet.')
            ->assertDontSee('Show changes');
    }

    public function test_no_releases_says_so(): void
    {
        $this->releases = [];

        $this->get('/games/tg/changes')->assertOk()->assertSee('The hub holds no releases of this game yet.');
    }

    public function test_a_query_without_the_filter_keys_changes_nothing(): void
    {
        $this->get('/games/tg/changes?utm=x')->assertOk()->assertSee('From v2 to v3:');
    }

    public function test_a_repeated_key_reads_its_first_value(): void
    {
        $this->get('/games/tg/changes?from=v1&from=v2&to=v3')->assertSee('From v1 to v3:');
    }

    public function test_an_unknown_game_is_a_404(): void
    {
        $this->get('/games/nope/changes')->assertNotFound();
    }
}
