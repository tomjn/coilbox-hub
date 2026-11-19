<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * How many Supabase requests an uncached unit page costs (#590). Search
 * crawlers visit each unit page once, so nearly every visit misses the page
 * cache. What one unit's page shares with the rest of its game is cached per
 * game, and the page itself makes at most two requests.
 */
class UnitPageRequestsTest extends TestCase
{
    protected const GAME = [
        'id' => 1, 'shortname' => 'BA', 'display_name' => 'Balanced Annihilation', 'description' => null, 'links' => [],
        'conquest_factions' => null, 'owner_user_id' => null, 'hidden_at' => null, 'base_game' => null,
        'logo_path' => null, 'logo_hash' => null, 'logo_staged_tier' => null,
        'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
        'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
        'game_download_source' => [], 'game_faction' => [['key' => 'arm', 'name' => 'ARM', 'logo_path' => null]],
        'game_version' => [['version' => 'r2', 'last_seen_at' => '2026-01-02']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'cache.default' => 'array']);
        $this->serve();
    }

    /** One unit row with every column a read may ask for. */
    private static function unit(string $name, ?string $label, array $options, array $morphs, array $stats, array $revisions, ?string $removed = null): array
    {
        return [
            'id' => crc32($name), 'unit_name' => $name, 'full_name' => $label, 'faction_key' => 'arm',
            'build_options' => $options, 'morph_targets' => $morphs, 'stats' => $stats,
            'snippet' => null, 'source_version' => 'r2', 'removed_at' => $removed,
            'game_unit_revision' => $revisions,
        ];
    }

    private static function revision(string $version, ?string $label, array $options, array $stats, array $morphs = []): array
    {
        return ['version' => $version, 'full_name' => $label, 'faction_key' => 'arm', 'build_options' => $options, 'stats' => $stats, 'morph_targets' => $morphs];
    }

    /** A small game: a commander that morphs, a lab, a builder, a retired shed and a unit with no pictures. */
    protected static function units(): array
    {
        return [
            self::unit('armcom', 'Commander', ['armsolar', 'armlab'], [['into' => 'armcom2', 'metal' => 600]], ['health' => 3700, 'metalCost' => 2700], [
                self::revision('r1', 'Commander Mk I', ['armsolar'], ['health' => 3000], [['into' => 'armcom2', 'metal' => 500]]),
                self::revision('r2', 'Commander', ['armsolar', 'armlab'], ['health' => 3700, 'metalCost' => 2700], [['into' => 'armcom2', 'metal' => 600]]),
            ]),
            self::unit('armcom2', 'Commander, level 2', ['armsolar', 'armlab', 'armfus'], [], ['health' => 4500], [
                self::revision('r1', 'Commander L2', ['armsolar', 'armlab'], ['health' => 4000]),
            ]),
            self::unit('armlab', 'Kbot Lab', ['armck'], [], ['health' => 2000], [
                self::revision('r1', 'Kbot Factory', ['armck'], ['health' => 1800]),
            ]),
            self::unit('armck', 'Construction Kbot', ['armsolar', 'armlab'], [], ['health' => 600], [
                self::revision('r1', 'Construction Kbot', ['armsolar'], ['health' => 550]),
            ]),
            self::unit('armsolar', 'Solar Collector', [], [], ['health' => 300], [
                self::revision('r1', null, [], ['health' => 280]),
            ]),
            // Added after r1, and the hub holds no picture of it.
            self::unit('armfus', 'Fusion Reactor', [], [], ['health' => 4000], []),
            self::unit('armshed', 'Kbot Shed', ['armck'], [], [], [
                self::revision('r1', 'Kbot Shed', ['armck'], []),
            ], '2026-01-01'),
        ];
    }

    private static function asset(string $unit, string $variant): array
    {
        return [
            'id' => md5($unit.$variant), 'game' => 'BA', 'unit_name' => $unit, 'map_name' => null, 'faction_key' => null,
            'variant' => $variant, 'tier' => 'static', 'path' => "units/BA/{$unit}/".str_replace(':', '-', $variant).'.webp',
            'width' => 256, 'height' => 256, 'moderation' => 'approved',
            'world_height_min' => null, 'world_height_max' => null, 'bytes_missing_at' => null,
        ];
    }

    protected static function tables(): array
    {
        return [
            'game' => [self::GAME],
            'game_browse' => [['shortname' => 'BA', 'faction_count' => 1, 'unit_count' => 6, 'item_count' => 0]],
            'game_version' => [['version' => 'r2', 'last_seen_at' => '2026-01-02'], ['version' => 'r1', 'last_seen_at' => '2026-01-01']],
            'game_faction' => [['key' => 'arm', 'name' => 'ARM']],
            'game_unit' => self::units(),
            'asset' => [
                self::asset('armcom', 'buildpic'),
                self::asset('armcom', 'render:angled'),
                self::asset('armsolar', 'buildpic'),
                self::asset('armlab', 'render:top'),
                self::asset('armcom2', 'buildpic'),
                self::asset('armck', 'buildpic'),
            ],
        ];
    }

    /**
     * A small PostgREST stand in: applies `eq`, `is`, `in` and `cs` on a row's
     * own columns, the embedded revision filters, `order`, `offset` and `limit`.
     * Any other table answers an empty list.
     */
    private function serve(): void
    {
        $tables = static::tables();
        Http::fake(function (Request $request) use ($tables) {
            $table = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $p = [];
            foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $part) {
                if ($part !== '') {
                    [$name, $value] = explode('=', $part, 2) + [1 => ''];
                    $p[urldecode($name)] = urldecode($value);
                }
            }
            $rows = $this->shape($tables[$table] ?? [], $p);
            foreach (array_reverse(explode(',', $p['order'] ?? '')) as $part) {
                if ($part !== '') {
                    [$column, $direction] = explode('.', $part) + [1 => 'asc'];
                    usort($rows, fn ($a, $b) => strcmp((string) $a[$column], (string) $b[$column]) * ($direction === 'desc' ? -1 : 1));
                }
            }
            $offset = (int) ($p['offset'] ?? 0);
            $page = array_slice($rows, $offset, min((int) ($p['limit'] ?? PHP_INT_MAX), 1000));

            return Http::response($page, 200, ['Content-Range' => $page === [] ? '*/'.count($rows) : $offset.'-'.($offset + count($page) - 1).'/'.count($rows)]);
        });
    }

    private function shape(array $rows, array $p): array
    {
        foreach ($p as $column => $expression) {
            if (in_array($column, ['select', 'order', 'offset', 'limit', 'or'], true) || str_contains($column, '.')) {
                continue;
            }
            $rows = array_values(array_filter($rows, fn ($row) => $this->satisfies($row[$column] ?? null, $expression)));
        }

        $select = $p['select'] ?? '';
        $out = [];
        foreach ($rows as $row) {
            if (! array_key_exists('game_unit_revision', $row)) {
                $out[] = $row;

                continue;
            }
            if (! str_contains($select, 'game_unit_revision')) {
                unset($row['game_unit_revision']);
                $out[] = $row;

                continue;
            }
            $revisions = $row['game_unit_revision'];
            if (isset($p['game_unit_revision.version'])) {
                $revisions = array_values(array_filter($revisions, fn ($r) => 'eq.'.$r['version'] === $p['game_unit_revision.version']));
            }
            if (isset($p['game_unit_revision.build_options'])) {
                $revisions = array_values(array_filter($revisions, fn ($r) => $this->satisfies($r['build_options'], $p['game_unit_revision.build_options'])));
            }
            if ($revisions === [] && str_contains($select, 'game_unit_revision!inner')) {
                continue;
            }
            $row['game_unit_revision'] = $revisions;
            $out[] = $row;
        }

        return $out;
    }

    private function satisfies(mixed $value, string $expression): bool
    {
        $list = fn (string $from) => array_map(fn ($item) => trim($item, '"'), explode(',', substr($expression, strlen($from), -1)));

        return match (true) {
            $expression === 'is.null' => $value === null,
            $expression === 'not.is.null' => $value !== null,
            str_starts_with($expression, 'eq.') => (string) $value === substr($expression, 3),
            str_starts_with($expression, 'in.(') => in_array($value, $list('in.('), true),
            str_starts_with($expression, 'cs.{') => array_diff($list('cs.{'), $value ?? []) === [],
            default => throw new LogicException("unhandled filter {$expression}"),
        };
    }

    /** Loads a page and returns how many Supabase requests it made. */
    protected function requestsFor(string $path): int
    {
        $before = Http::recorded()->count();
        $this->get($path)->assertOk();

        return Http::recorded()->count() - $before;
    }

    public function test_a_cold_unit_page_makes_at_most_two_requests_once_its_game_is_cached(): void
    {
        // Another unit's page fills the game's shared entries.
        $this->requestsFor('/games/BA/units/armsolar');

        $this->assertLessThanOrEqual(2, $this->requestsFor('/games/BA/units/armck'));
        $this->assertLessThanOrEqual(2, $this->requestsFor('/games/BA/units/armfus'));
        // A unit that morphs reads its stages' stats as well as its own row.
        $this->assertLessThanOrEqual(2, $this->requestsFor('/games/BA/units/armcom'));
    }

    public function test_a_cold_page_at_an_older_release_makes_at_most_two_requests_once_that_release_is_cached(): void
    {
        $this->requestsFor('/games/BA/units/armsolar@r1');

        $this->assertLessThanOrEqual(2, $this->requestsFor('/games/BA/units/armck@r1'));
        $this->assertLessThanOrEqual(2, $this->requestsFor('/games/BA/units/armcom@r1'));
    }
}
