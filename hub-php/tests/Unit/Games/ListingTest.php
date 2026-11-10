<?php

namespace Tests\Unit\Games;

use App\Domain\Assets\Cdn;
use App\Domain\Games\GameArt;
use App\Domain\Games\GameList;
use App\Domain\Games\Labels;
use App\Domain\Games\Query;
use Tests\TestCase;

/** `lib/games/labels.test.ts`, `lib/games/query.test.ts` and `lib/api/gameList.test.ts`. */
class ListingTest extends TestCase
{
    private function game(array $fields): array
    {
        return $fields + [
            'display_name' => null, 'description' => null,
            'logo_path' => null, 'logo_hash' => null, 'logo_staged_tier' => null,
            'featured_at' => null, 'downloads' => [],
            'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
            'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
            'faction_count' => 0, 'unit_count' => 0, 'item_count' => 0, 'base_game' => null,
        ];
    }

    /** @param list<array> $games */
    private function sorted(array $games): array
    {
        usort($games, Query::compareGames(...));

        return array_map(fn ($g) => $g['shortname'], $games);
    }

    public function test_a_backfilled_game_is_called_by_its_shortname(): void
    {
        $this->assertSame('BA', Labels::gameTitle(['shortname' => 'BA', 'display_name' => null]));
        $this->assertSame('Balanced Annihilation', Labels::gameTitle(['shortname' => 'BA', 'display_name' => 'Balanced Annihilation']));
    }

    public function test_counts_read_as_one_sentence_and_zero_is_printed(): void
    {
        $this->assertSame('2 factions, 340 units', Labels::gameCountLabel(['faction_count' => 2, 'unit_count' => 340]));
        $this->assertSame('1 faction, 0 units', Labels::gameCountLabel(['faction_count' => 1, 'unit_count' => 0]));
        $this->assertSame([['count' => 1, 'noun' => 'faction'], ['count' => 35, 'noun' => 'units']], Labels::gameCountParts(['faction_count' => 1, 'unit_count' => 35]));
    }

    public function test_community_counts_handle_the_singular_and_nothing(): void
    {
        $this->assertSame('0 community items', Labels::itemCountLabel(0));
        $this->assertSame('1 community item', Labels::itemCountLabel(1));
        $this->assertSame('40 community items', Labels::itemCountLabel(40));
        $this->assertSame('Nothing published yet', Labels::itemCardLabel(0));
        $this->assertSame('3 community items', Labels::itemCardLabel(3));
    }

    public function test_a_description_that_only_repeats_the_name_says_nothing(): void
    {
        $bota = ['shortname' => 'BOTA', 'display_name' => 'Basically OTA'];
        $this->assertFalse(Labels::saysMoreThanName($bota, 'BOTA'));
        $this->assertFalse(Labels::saysMoreThanName($bota, ' basically ota '));
        $this->assertFalse(Labels::saysMoreThanName($bota, null));
        $this->assertFalse(Labels::saysMoreThanName($bota, ''));
        $this->assertTrue(Labels::saysMoreThanName($bota, 'A game by Fang'));
    }

    public function test_the_sides_read_as_a_choice(): void
    {
        $this->assertSame('Play as ARM or CORE.', Labels::playAsLabel(['ARM', 'CORE']));
        $this->assertSame('Play as Armada, Cortex, or Legion.', Labels::playAsLabel(['Armada', 'Cortex', 'Legion']));
        $this->assertNull(Labels::playAsLabel([]));
    }

    public function test_games_sort_by_the_name_a_reader_sees(): void
    {
        $this->assertSame(['BAR', 'AA', 'ZK'], $this->sorted([
            $this->game(['shortname' => 'ZK', 'display_name' => 'Zero-K']),
            $this->game(['shortname' => 'BAR', 'display_name' => 'Beyond All Reason']),
            $this->game(['shortname' => 'AA', 'display_name' => 'Metal Factions']),
        ]));
        $this->assertSame(['Alpha', 'Zed'], $this->sorted([$this->game(['shortname' => 'Zed']), $this->game(['shortname' => 'Alpha'])]));
    }

    public function test_featured_games_come_first_alphabetical_among_themselves(): void
    {
        $this->assertSame(['MMM', 'ZZZ', 'AAA'], $this->sorted([
            $this->game(['shortname' => 'AAA']),
            $this->game(['shortname' => 'ZZZ', 'featured_at' => '2026-09-18T00:00:00Z']),
            $this->game(['shortname' => 'MMM', 'featured_at' => '2026-09-17T00:00:00Z']),
        ]));
    }

    public function test_unit_count_does_not_decide_the_order_and_shortname_breaks_ties(): void
    {
        $this->assertSame(['huge', 'small'], $this->sorted([$this->game(['shortname' => 'small', 'unit_count' => 2]), $this->game(['shortname' => 'huge', 'unit_count' => 900])]));
        $this->assertSame(['AA', 'BB'], $this->sorted([$this->game(['shortname' => 'BB', 'display_name' => 'Same']), $this->game(['shortname' => 'AA', 'display_name' => 'Same'])]));
    }

    public function test_the_top_level_list_leaves_out_mutators(): void
    {
        $listed = Query::withoutMutators([
            $this->game(['shortname' => 'BAR']),
            $this->game(['shortname' => 'BAR-Rush', 'base_game' => 'BAR']),
            $this->game(['shortname' => 'Orphan', 'base_game' => 'NotOnTheHub']),
        ]);
        $this->assertSame(['BAR'], array_map(fn ($g) => $g['shortname'], $listed));
    }

    public function test_the_api_envelope_and_a_bare_game(): void
    {
        $this->assertSame(['format' => 'coilbox-hub-games', 'version' => 1, 'games' => []], GameList::body([]));
        $this->assertSame([
            'shortname' => 'BA', 'title' => 'BA', 'description' => null, 'featured' => false, 'downloads' => [],
            'logo' => null, 'card' => null, 'banner' => null,
            'faction_count' => 0, 'unit_count' => 0, 'item_count' => 0, 'base_game' => null,
        ], GameList::body([$this->game(['shortname' => 'BA'])])['games'][0]);
    }

    public function test_the_api_publishes_base_game_title_downloads_and_featured(): void
    {
        $this->assertSame('BAR', GameList::body([$this->game(['shortname' => 'BAR-Rush', 'base_game' => 'BAR'])])['games'][0]['base_game']);
        $this->assertSame('Beyond All Reason', GameList::body([$this->game(['shortname' => 'BAR', 'display_name' => 'Beyond All Reason'])])['games'][0]['title']);
        $this->assertTrue(GameList::body([$this->game(['shortname' => 'BA', 'featured_at' => '2026-09-18T00:00:00Z'])])['games'][0]['featured']);

        $downloads = [
            ['kind' => 'github', 'value' => 'springraaar/metal_factions', 'asset' => 'metal_factions'],
            ['kind' => 'rapid', 'value' => 'metalfactions:stable'],
        ];
        $this->assertSame($downloads, GameList::body([$this->game(['shortname' => 'MF', 'downloads' => $downloads])])['games'][0]['downloads']);
        // A url source carried over from the single column has no filename. It is still published.
        $legacy = [['kind' => 'url', 'value' => 'https://example.test/mf.sdz']];
        $this->assertSame($legacy, GameList::body([$this->game(['shortname' => 'MF', 'downloads' => $legacy])])['games'][0]['downloads']);
    }

    public function test_the_api_keeps_the_order_it_was_given(): void
    {
        $body = GameList::body([$this->game(['shortname' => 'Pinned', 'featured_at' => '2026-09-18T00:00:00Z']), $this->game(['shortname' => 'Ordinary'])]);
        $this->assertSame(['Pinned', 'Ordinary'], array_map(fn ($g) => $g['shortname'], $body['games']));
    }

    public function test_promoted_art_is_its_durable_address_and_staged_art_the_hubs_route(): void
    {
        $row = GameList::body([$this->game(['shortname' => 'BA', 'card_path' => 'games/BA/card.webp', 'banner_path' => 'games/BA/banner.webp'])])['games'][0];
        $this->assertSame(Cdn::staticTierUrl('games/BA/card.webp'), $row['card']);
        $this->assertSame(Cdn::staticTierUrl('games/BA/banner.webp'), $row['banner']);

        $this->assertSame('/assets/games/A%20B/logo/abc', GameArt::url('A B', 'logo', ['path' => 'games/A B/logo.png', 'hash' => 'abc', 'staged_tier' => 'bucket']));
        // A staged tier nothing writes any more is not drawn.
        $this->assertNull(GameArt::url('BA', 'logo', ['path' => 'games/BA/logo.png', 'hash' => 'abc', 'staged_tier' => 'blob']));
        $this->assertNull(GameArt::url('BA', 'logo', ['path' => null, 'hash' => null, 'staged_tier' => null]));
    }
}
