<?php

namespace Tests\Unit\Games;

use App\Domain\Games\CompareUnits;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/games/compareUnits.test.ts`, plus the pair loader. */
class CompareUnitsTest extends TestCase
{
    private const UNITS = [
        ['name' => 'armcom', 'label' => 'Commander'],
        ['name' => 'corcom', 'label' => 'Commander'],
        ['name' => 'armmex', 'label' => 'Metal Extractor'],
        ['name' => 'armlab', 'label' => 'Bot Lab'],
    ];

    private const RELEASES = ['1.2', '1.1', '1.0'];

    public function test_an_id_resolves_to_its_unit(): void
    {
        $this->assertSame(
            ['kind' => 'found', 'unit' => ['name' => 'armmex', 'label' => 'Metal Extractor']],
            CompareUnits::resolveWithUnit('armmex', 'armcom', self::UNITS),
        );
    }

    public function test_an_id_matches_whatever_case_it_is_typed_in(): void
    {
        $this->assertSame('found', CompareUnits::resolveWithUnit('ARMMEX', 'armcom', self::UNITS)['kind']);
    }

    public function test_a_display_name_that_names_one_unit_resolves_to_it(): void
    {
        $this->assertSame(
            ['kind' => 'found', 'unit' => ['name' => 'armlab', 'label' => 'Bot Lab']],
            CompareUnits::resolveWithUnit('bot lab', 'armcom', self::UNITS),
        );
    }

    public function test_a_display_name_shared_by_several_units_lists_them(): void
    {
        $this->assertSame(
            ['kind' => 'ambiguous', 'choices' => [['name' => 'armcom', 'label' => 'Commander'], ['name' => 'corcom', 'label' => 'Commander']]],
            CompareUnits::resolveWithUnit('Commander', 'armmex', self::UNITS),
        );
    }

    public function test_an_id_wins_over_a_display_name_that_happens_to_match(): void
    {
        $tricky = [...self::UNITS, ['name' => 'other', 'label' => 'armmex']];
        $result = CompareUnits::resolveWithUnit('armmex', 'armcom', $tricky);

        $this->assertSame('found', $result['kind']);
        $this->assertSame('armmex', $result['unit']['name']);
    }

    public function test_a_name_the_game_does_not_hold_is_unknown(): void
    {
        $this->assertSame(['kind' => 'unknown', 'asked' => 'nope'], CompareUnits::resolveWithUnit('nope', 'armcom', self::UNITS));
        $this->assertSame(['kind' => 'unknown', 'asked' => 'nope'], CompareUnits::resolveWithUnit(' nope ', 'armcom', self::UNITS));
    }

    public function test_the_unit_itself_by_id_or_by_a_name_only_it_holds_is_self(): void
    {
        $this->assertSame(['kind' => 'self'], CompareUnits::resolveWithUnit('armcom', 'armcom', self::UNITS));
        $this->assertSame(['kind' => 'self'], CompareUnits::resolveWithUnit('Bot Lab', 'armlab', self::UNITS));
    }

    public function test_a_shared_display_name_that_includes_the_unit_itself_still_offers_the_others(): void
    {
        $result = CompareUnits::resolveWithUnit('Commander', 'armcom', self::UNITS);

        $this->assertSame('found', $result['kind']);
        $this->assertSame('corcom', $result['unit']['name']);
    }

    public function test_nothing_typed_asks_for_a_choice(): void
    {
        $this->assertSame(['kind' => 'none'], CompareUnits::resolveWithUnit('  ', 'armcom', self::UNITS));
        $this->assertSame(['kind' => 'none'], CompareUnits::resolveWithUnit(null, 'armcom', self::UNITS));
    }

    public function test_the_default_is_the_newest_release_holding_both_units(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2', '1.1', '1.0'], ['1.1', '1.0'], null);

        $this->assertSame(['release' => '1.1', 'both' => ['1.1', '1.0'], 'missing' => null, 'unknownRelease' => false], $pick);
    }

    public function test_no_release_holding_both_gives_no_default(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2'], ['1.0'], null);

        $this->assertNull($pick['release']);
        $this->assertSame([], $pick['both']);
    }

    public function test_a_release_both_hold_is_used_as_asked(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2', '1.1', '1.0'], ['1.1', '1.0'], '1.0');

        $this->assertSame('1.0', $pick['release']);
        $this->assertNull($pick['missing']);
    }

    public function test_a_release_one_unit_lacks_says_which_side_is_missing(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2', '1.1', '1.0'], ['1.1', '1.0'], '1.2');

        $this->assertNull($pick['release']);
        $this->assertSame(['a' => false, 'b' => true], $pick['missing']);
        $this->assertSame(['1.1', '1.0'], $pick['both']);
    }

    public function test_a_release_the_game_does_not_hold_is_flagged(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2'], ['1.2'], '9.9');

        $this->assertNull($pick['release']);
        $this->assertTrue($pick['unknownRelease']);
        $this->assertSame(['1.2'], $pick['both']);
    }

    public function test_an_empty_release_is_the_same_as_none_asked(): void
    {
        $pick = CompareUnits::pickCompareRelease(self::RELEASES, ['1.2'], ['1.2'], '');

        $this->assertSame('1.2', $pick['release']);
        $this->assertFalse($pick['unknownRelease']);
    }

    private function fakeUnits(array $rows): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/game_unit*' => Http::response($rows)]);
    }

    public function test_the_pair_loads_in_the_order_asked_with_every_revision(): void
    {
        $this->fakeUnits([
            ['unit_name' => 'corcom', 'full_name' => 'Commander', 'game' => ['shortname' => 'BA'], 'game_unit_revision' => [['version' => '1.0', 'full_name' => null, 'faction_key' => 'core', 'stats' => ['health' => 1]]]],
            ['unit_name' => 'armcom', 'full_name' => 'Commander', 'game' => ['shortname' => 'BA'], 'game_unit_revision' => null],
        ]);

        $pair = CompareUnits::loadUnitPair(Supabase::anon(), 'BA', 'armcom', 'corcom');

        $this->assertSame(['armcom', 'corcom'], [$pair[0]['unit_name'], $pair[1]['unit_name']]);
        $this->assertSame([], $pair[0]['revisions']);
        $this->assertSame('1.0', $pair[1]['revisions'][0]['version']);
        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, 'game.shortname=eq.BA')
                && str_contains($url, 'unit_name=in.(armcom,corcom)')
                && str_contains($url, 'select=unit_name,full_name,game!inner(shortname),game_unit_revision(version,full_name,faction_key,stats)');
        });
    }

    public function test_the_pair_is_null_when_one_unit_is_missing_or_the_read_fails(): void
    {
        $this->fakeUnits([['unit_name' => 'armcom', 'full_name' => null, 'game_unit_revision' => []]]);
        $this->assertNull(CompareUnits::loadUnitPair(Supabase::anon(), 'BA', 'armcom', 'corcom'));

        Http::fake(['*/rest/v1/game_unit*' => Http::response(['message' => 'nope', 'code' => '42501'], 401)]);
        $this->assertNull(CompareUnits::loadUnitPair(Supabase::anon(), 'BA', 'armcom', 'corcom'));
    }
}
