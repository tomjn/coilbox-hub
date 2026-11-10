<?php

namespace Tests\Unit\Games;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Have;
use App\Domain\Games\Sides;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/games/sides.test.ts`: which start unit a side is drawn with,
 * against rows shaped like the reads. Random is not a side, a start unit the
 * catalog files under no side is not drawn, a start unit with no buildpic held
 * is drawn with a placeholder rather than dropped, and a game that has named
 * no start unit at all falls back to a random sample of its own units.
 */
class SidesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function held(string ...$unitNames): array
    {
        $held = [];
        foreach ($unitNames as $spec) {
            [$game, $unitName] = explode(':', $spec);
            $held[Have::identityKey(AssetResolver::unit($game, $unitName))] = [
                'tier' => 'static',
                'path' => "units/{$game}/{$unitName}.webp",
                'width' => 64,
                'height' => 64,
                'moderation' => 'approved',
                'world_height_min' => null,
                'world_height_max' => null,
            ];
        }

        return $held;
    }

    /** @return array<string, mixed> */
    private function byar(array $over = []): array
    {
        return [
            'shortname' => 'BYAR',
            'start_units' => ['armcom', 'corcom', 'dummycom', 'legcom'],
            'game_faction' => [
                ['key' => 'legion', 'name' => 'Legion'],
                ['key' => 'random', 'name' => 'Random'],
                ['key' => 'armada', 'name' => 'Armada'],
                ['key' => 'cortex', 'name' => 'Cortex'],
            ],
            ...$over,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function units(): array
    {
        return [
            ['unit_name' => 'armcom', 'full_name' => 'Armada Commander', 'faction_key' => 'armada', 'game' => ['shortname' => 'BYAR']],
            ['unit_name' => 'corcom', 'full_name' => null, 'faction_key' => 'cortex', 'game' => ['shortname' => 'BYAR']],
            ['unit_name' => 'dummycom', 'full_name' => 'Random', 'faction_key' => 'random', 'game' => ['shortname' => 'BYAR']],
        ];
    }

    public function test_sides_are_alphabetical_with_random_left_out(): void
    {
        $sides = Sides::assembleSides([$this->byar()], $this->units(), $this->held('BYAR:armcom'));

        $this->assertSame(['Armada', 'Cortex', 'Legion'], array_column($sides['BYAR']['factions'], 'name'));
    }

    public function test_a_side_is_drawn_with_its_start_unit_only_when_the_catalog_places_it(): void
    {
        $sides = Sides::assembleSides([$this->byar()], $this->units(), $this->held('BYAR:armcom', 'BYAR:dummycom'));
        $commanders = $sides['BYAR']['commanders'];

        $this->assertSame('Armada Commander', $commanders['armada']['label']);
        // legcom is not in the catalog at all, and dummycom belongs to the side
        // that is not one.
        $this->assertArrayNotHasKey('legion', $commanders);
        $this->assertArrayNotHasKey('random', $commanders);
    }

    /**
     * Whether a picture has been uploaded is not a fact about the unit, so the
     * side keeps its start unit either way and the ladder answers with the
     * placeholder.
     */
    public function test_a_start_unit_with_no_buildpic_held_is_drawn_as_a_placeholder(): void
    {
        $sides = Sides::assembleSides([$this->byar()], $this->units(), $this->held('BYAR:armcom'));
        $commanders = $sides['BYAR']['commanders'];

        $this->assertSame('static', $commanders['armada']['picture']['from']);
        $this->assertSame('placeholder', $commanders['cortex']['picture']['from']);
        // No full_name reported, so the def name is the label.
        $this->assertSame('corcom', $commanders['cortex']['label']);
    }

    public function test_a_unit_is_only_matched_within_its_own_game(): void
    {
        $other = $this->byar(['shortname' => 'XTA', 'start_units' => ['armcom']]);

        $sides = Sides::assembleSides([$other], $this->units(), $this->held('XTA:armcom'));

        $this->assertCount(0, $sides['XTA']['commanders']);
    }

    public function test_a_game_with_no_start_units_still_lists_its_sides(): void
    {
        $sides = Sides::assembleSides([$this->byar(['start_units' => null])], [], []);

        $this->assertCount(3, $sides['BYAR']['factions']);
        $this->assertCount(0, $sides['BYAR']['commanders']);
    }

    public function test_a_game_gets_a_sample_when_nothing_it_names_lands_on_one_of_its_sides(): void
    {
        $this->assertSame(['BYAR'], Sides::gamesNeedingSample([$this->byar(['start_units' => null])], []));
        $this->assertSame(['BYAR'], Sides::gamesNeedingSample([$this->byar(['start_units' => []])], []));
        // Named, and every one of them unplaceable: legcom is unknown and
        // dummycom is filed under Random, which is not a side.
        $this->assertSame(['BYAR'], Sides::gamesNeedingSample([$this->byar(['start_units' => ['legcom', 'dummycom']])], $this->units()));
        $this->assertSame([], Sides::gamesNeedingSample([$this->byar()], $this->units()));
    }

    public function test_a_sampled_unit_is_drawn_like_a_start_unit_and_only_when_there_are_none(): void
    {
        $sampled = ['BYAR' => [
            ['unit_name' => 'armflash', 'full_name' => 'Flash'],
            ['unit_name' => 'corraid', 'full_name' => null],
        ]];

        $none = Sides::assembleSides([$this->byar(['start_units' => null])], [], [], $sampled);
        $this->assertSame(['Flash', 'corraid'], array_column($none['BYAR']['sample'], 'label'));
        $this->assertSame('placeholder', $none['BYAR']['sample'][0]['picture']['from']);

        $some = Sides::assembleSides([$this->byar()], $this->units(), $this->held('BYAR:armcom'), $sampled);
        $this->assertSame([], $some['BYAR']['sample']);
    }

    public function test_the_loader_reads_games_then_units_then_a_sample_then_pictures(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        $named = $this->byar(['start_units' => ['armcom']]);
        $unnamed = $this->byar(['shortname' => 'ZK', 'start_units' => null]);
        Http::fake([
            '*/rest/v1/game_unit*' => Http::response([$this->units()[0]]),
            '*/rest/v1/game?*' => Http::response([$named, $unnamed]),
            '*/rest/v1/rpc/game_sample_units' => Http::response([
                ['shortname' => 'ZK', 'unit_name' => 'zkcom', 'full_name' => 'Zero Commander'],
            ]),
            '*/rest/v1/asset*' => Http::response([]),
        ]);

        $sides = Sides::loadGameSides(Supabase::anon(), ['BYAR', 'ZK']);

        $this->assertSame('Armada Commander', $sides['BYAR']['commanders']['armada']['label']);
        $this->assertSame([], $sides['BYAR']['sample']);
        $this->assertSame(['Zero Commander'], array_column($sides['ZK']['sample'], 'label'));
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'rpc/game_sample_units')
                && $request->data() === ['p_shortnames' => ['ZK'], 'p_count' => Sides::SAMPLE_SIZE];
        });
    }

    public function test_the_loader_gives_nothing_for_no_games_or_a_failed_read(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertSame([], Sides::loadGameSides(Supabase::anon(), []));
        $this->assertSame([], Sides::loadGameSides(Supabase::anon(), ['BYAR']));
    }
}
