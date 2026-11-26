<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\ReplaySummary;
use PHPUnit\Framework\TestCase;

class ReplaySummaryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function summary(): array
    {
        return [
            'v' => 1, 'engine' => '2025.04.08', 'game' => 'Beyond All Reason test-27000-abc', 'map' => 'Supreme Isthmus v2.1',
            'started_at' => 1794787260, 'duration' => 0,
            'players' => [['name' => 'Alice', 'team' => 0, 'ally_team' => 0, 'faction' => null]],
            'spectators' => [], 'winners' => [],
            'totals' => [['team' => 0] + array_fill_keys(ReplaySummary::TOTALS, 0.5)],
            'start_positions' => [], 'build_orders' => [], 'units' => [],
        ];
    }

    public function test_a_whole_summary_is_answered_as_sent(): void
    {
        $this->assertSame(self::summary(), ReplaySummary::parse(self::summary()));
    }

    public function test_each_part_is_checked(): void
    {
        $cases = [
            'summary must be a JSON object.' => [1, 2],
            'summary.v must be 1.' => ['v' => 2] + self::summary(),
            'summary.map must be 1 to 255 characters.' => ['map' => ' '] + self::summary(),
            'summary.duration must be a whole number of game seconds, at least 0.' => ['duration' => -1] + self::summary(),
            'summary.players must be a list with at least one player.' => ['players' => []] + self::summary(),
            'summary.players[0] needs a name, a team, an ally_team and a faction that is text or null.' => ['players' => [['name' => 'Alice', 'team' => 0]]] + self::summary(),
            'summary.spectators must be a list of names.' => ['spectators' => [3]] + self::summary(),
            'summary.winners must be a list of ally team numbers.' => ['winners' => [-1]] + self::summary(),
            'summary.totals[0].units_killed must be a number.' => ['totals' => [['team' => 0] + array_fill_keys(array_slice(ReplaySummary::TOTALS, 0, -1), 1)]] + self::summary(),
            'summary.start_positions[0] needs a team, an x and a z.' => ['start_positions' => [['team' => 0, 'x' => 1]]] + self::summary(),
            'summary.units lists unit_def_id 4 twice.' => ['units' => array_fill(0, 2, ['unit_def_id' => 4, 'name' => 'a', 'metal' => 1, 'energy' => 1])] + self::summary(),
            'summary.build_orders[0] needs a team and a list of orders.' => ['build_orders' => [['team' => 0]]] + self::summary(),
        ];
        foreach ($cases as $message => $summary) {
            $this->assertSame($message, ReplaySummary::parse($summary));
        }
    }

    public function test_a_name_is_found_among_players_and_spectators_exactly(): void
    {
        $summary = ['spectators' => ['Carol']] + self::summary();

        $this->assertTrue(ReplaySummary::names($summary, 'Alice'));
        $this->assertTrue(ReplaySummary::names($summary, 'Carol'));
        $this->assertFalse(ReplaySummary::names($summary, 'alice'));
    }
}
