<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\Lua\Awards;
use App\Domain\Replays\Lua\UnitList;
use App\Domain\Replays\Replay;
use App\Domain\Replays\ReplayReader;
use App\Domain\Replays\Stream\Event;
use App\Domain\Replays\Stream\Order;
use PHPUnit\Framework\TestCase;

/**
 * Reads real replays. The Beyond All Reason values were checked against what
 * Beyond All Reason's replay site gives for the same match, game id
 * 3d92086b0b2c592e3aea5585ff7aa2fa: start positions, ally teams, winners,
 * faction picks and awards. Each player's command count in the end of game
 * block equals the orders they sent from their selection, in all three games.
 */
class ReplayReaderTest extends TestCase
{
    private function read(string $name): Replay
    {
        return (new ReplayReader)->read(__DIR__.'/../../Fixtures/replays/'.$name);
    }

    /** @return array<string, int> orders by player and origin, such as `3/lua` */
    private function orderCounts(Replay $replay): array
    {
        $counts = [];
        foreach ($replay->stream->orders as $order) {
            $key = $order->player.'/'.$order->origin;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    public function test_it_reads_a_beyond_all_reason_autohost_replay(): void
    {
        $r = $this->read('bar-tangerine.sdfz');

        $h = $r->header;
        $this->assertSame('2026.09.03', $h->engineVersion);
        $this->assertSame('3d92086b0b2c592e3aea5585ff7aa2fa', $h->gameId);
        $this->assertSame(1795723837, $h->startTime);
        $this->assertSame(935, $h->gameTime);
        $this->assertSame(1127, $h->wallclockTime);
        $this->assertSame(15, $h->teamStatPeriod);

        $s = $r->script;
        $this->assertSame('Tangerine_Remake 1.0', $s->map);
        $this->assertSame('Beyond All Reason test-32071-e1cbffe', $s->gameType);
        $this->assertCount(8, $s->players);
        $this->assertCount(6, $s->teams);
        $this->assertSame([], $s->ais);
        $charlson = $s->players[3];
        $this->assertSame('Charlson', $charlson->name);
        $this->assertSame([3, 0, 'Cortex', 'EG', '[23.22]', 23.22, 6.68], [
            $charlson->team, $charlson->allyTeam, $charlson->side, $charlson->countryCode,
            $charlson->skill, $charlson->rating(), $charlson->skillUncertainty,
        ]);
        $this->assertTrue($s->players[6]->spectator);
        $this->assertNull($s->players[6]->team);
        $this->assertSame(['left' => 0.0, 'top' => 0.0, 'right' => 1.0, 'bottom' => 0.3], $s->allyTeams[0]->startBox);
        $this->assertSame(['left' => 0.0, 'top' => 0.7, 'right' => 1.0, 'bottom' => 1.0], $s->allyTeams[1]->startBox);

        $starts = $r->stream->startPositions();
        $this->assertSame([0, 1, 2, 3, 4, 5], array_keys($starts));
        $this->assertSame([5113.47314453125, 78.6676025390625, 1628.724853515625], [$starts[3]->x, $starts[3]->y, $starts[3]->z]);
        $this->assertSame([7182.08837890625, 54.47607421875, 6871.1025390625], [$starts[0]->x, $starts[0]->y, $starts[0]->z]);

        $this->assertSame(92244, $r->stream->packets);
        $this->assertSame(28071, $r->stream->lastFrame);
        $this->assertSame([
            '0/lua' => 319, '0/selection' => 139, '1/lua' => 393, '1/selection' => 86,
            '2/lua' => 295, '2/selection' => 145, '3/lua' => 349, '3/selection' => 107,
            '4/lua' => 195, '4/selection' => 85, '5/lua' => 225, '5/selection' => 134,
        ], $this->orderCounts($r));
        $this->assertCount(76, $r->stream->selections);
        $this->assertCount(46, $r->stream->chat);
        $this->assertSame('ex go ahead', $r->stream->chat[0]->text);
        $this->assertSame(-1, $r->stream->chat[0]->frame);
        $this->assertCount(24, $r->stream->systemMessages);

        $died = $r->stream->eventsOf(Event::TEAM_DIED);
        $this->assertSame([0, 1, 2], array_map(fn (Event $e) => $e->data['team'], $died));
        $this->assertSame(27717, $died[0]->frame);
        $gameOver = $r->stream->eventsOf(Event::GAME_OVER);
        $this->assertCount(7, $gameOver);
        $this->assertSame([0], $gameOver[0]->data['winning_ally_teams']);
        $this->assertSame([[6, 'left'], [0, 'left']], array_map(fn (Event $e) => [$e->player, $e->data['reason']], $r->stream->eventsOf(Event::PLAYER_LEFT)));

        $end = $r->endOfGame;
        $this->assertNotNull($end);
        $this->assertSame([0], $end->winningAllyTeams);
        $this->assertSame([1, 1, 1, 1, 1, 1], array_map('count', $end->teams));
        $this->assertSame([139, 86, 145, 107, 85, 134, 0, 0], array_map(fn ($p) => $p->commands, $end->players));
        $this->assertSame(182, $end->players[3]->unitCommands);
        $team2 = $end->finalTotals(2);
        $this->assertSame(28350, $team2->frame);
        $this->assertSame(303891.40625, $team2->metalProduced);
        $this->assertSame(1299408.625, $team2->damageReceived);
        $this->assertSame([653, 653, 100], [$team2->unitsProduced, $team2->unitsDied, $team2->unitsKilled]);
        $team5 = $end->finalTotals(5);
        $this->assertSame(1050611.125, $team5->metalProduced);
        $this->assertSame(1415866.375, $team5->damageDealt);
    }

    public function test_it_decodes_beyond_all_reasons_lua_messages(): void
    {
        $stream = $this->read('bar-tangerine.sdfz')->stream;
        $this->assertSame(54331, $stream->luaMessageCount);

        $lists = $stream->luaMessagesOf('unit_list');
        $this->assertCount(7, $lists);
        $units = $lists[0]->value;
        $this->assertInstanceOf(UnitList::class, $units);
        $this->assertCount(1235, $units->names);
        $this->assertSame('arm_hat_halloween_witch', $units->name(1));

        // The last pick before the game counts. The site shows Legion for
        // players 1 and 3 and Cortex for players 2 and 5. Players 0 and 4
        // never changed from their side in the start script.
        $last = [];
        foreach ($stream->luaMessagesOf('faction_pick') as $m) {
            $this->assertSame(-1, $m->frame);
            $last[$m->player] = $units->name($m->value->unitDefId);
        }
        ksort($last);
        $this->assertSame([1 => 'legcom', 2 => 'corcom', 3 => 'legcom', 5 => 'corcom'], $last);
        $this->assertCount(8, $stream->luaMessagesOf('faction_pick'));

        $awards = $stream->luaMessagesOf('awards');
        $this->assertCount(7, $awards);
        $this->assertSame(27912, $awards[0]->frame);
        $value = $awards[0]->value;
        $this->assertInstanceOf(Awards::class, $value);
        $this->assertCount(13, $value->awards);
        $this->assertSame(
            [[5, 18970600.0], [3, 10094460.0], [4, 9247160.0]],
            array_map(fn ($a) => [$a->team, $a->value], $value->named('fightingUnitsDestroyed')),
        );
        $this->assertSame([[2, 1299408.63]], array_map(fn ($a) => [$a->team, $a->value], $value->named('mostDamageTaken')));
        $this->assertSame([[null, null]], array_map(fn ($a) => [$a->team, $a->value], $value->named('cow')));
    }

    public function test_it_reads_a_splinter_faction_replay(): void
    {
        $r = $this->read('sf-tumult.sdfz');

        $this->assertSame('2026.09.01', $r->header->engineVersion);
        $this->assertSame('9cf5d76a4468b019403831ac99faf0f7', $r->header->gameId);
        $this->assertSame(501, $r->header->gameTime);
        $this->assertSame('SplinterFaction 0.1.95', $r->script->gameType);
        $this->assertSame('Tumult', $r->script->map);
        $this->assertSame('Player', $r->script->players[0]->name);
        $ai = $r->script->ais[0];
        $this->assertSame(['Enemy', 'NullAI', 1, 1, 0], [$ai->name, $ai->shortName, $ai->team, $ai->allyTeam, $ai->host]);
        $this->assertNull($r->script->allyTeams[0]->startBox);

        $starts = $r->stream->startPositions();
        $this->assertSame([[2537.0, 0.0, 918.0], [4631.0, 0.0, 6250.0]], array_map(fn ($p) => [$p->x, $p->y, $p->z], array_values($starts)));

        $this->assertSame(['0/lua' => 794, '0/selection' => 54], $this->orderCounts($r));
        $this->assertSame(18347, $r->stream->packets);
        $this->assertSame(1588, $r->stream->luaMessageCount);

        $picks = $r->stream->luaMessagesOf('faction_pick');
        $this->assertCount(1, $picks);
        $this->assertSame([41, 0, 19, null], [$picks[0]->frame, $picks[0]->player, $picks[0]->value->unitDefId, $picks[0]->value->faction]);

        $end = $r->endOfGame;
        $this->assertSame([0], $end->winningAllyTeams);
        $this->assertSame([35, 35], array_map('count', $end->teams));
        $this->assertSame(54, $end->players[0]->commands);
        $this->assertSame(15054, $r->stream->eventsOf(Event::GAME_OVER)[0]->frame);
        $team0 = $end->finalTotals(0);
        $this->assertSame([15300, 9363.98046875, 8850.0, 71, 6], [$team0->frame, $team0->metalProduced, $team0->damageDealt, $team0->unitsProduced, $team0->unitsDied]);
        $this->assertSame(8850.0, $end->finalTotals(1)->damageReceived);
    }

    public function test_it_reads_a_metal_factions_replay_with_no_game_over(): void
    {
        $r = $this->read('mf-valles.sdfz');

        $this->assertSame('2026.09.01-70-g811d477 macos/integration-next', $r->header->engineVersion);
        $this->assertSame(0, $r->header->gameTime);
        $this->assertSame('Metal Factions v2.58', $r->script->gameType);
        $this->assertSame('Valles Marineris 2.6.1', $r->script->map);
        $probe = $r->script->players[0];
        $this->assertSame(['probe', 0, 0, 'aven', [1.0, 0.0, 0.0]], [$probe->name, $probe->team, $probe->allyTeam, $probe->side, $probe->colour]);

        $starts = $r->stream->startPositions();
        $this->assertSame([672.0, 0.0, 6135.0], [$starts[0]->x, $starts[0]->y, $starts[0]->z]);
        $this->assertSame(['0/selection' => 1], $this->orderCounts($r));
        $this->assertSame(Order::SELECTION, iterator_to_array($r->stream->orders)[0]->origin);
        $pause = $r->stream->eventsOf(Event::PAUSE);
        $this->assertSame([[90, 0, true]], array_map(fn (Event $e) => [$e->frame, $e->player, $e->data['paused']], $pause));
        $this->assertSame(94, $r->stream->luaMessageCount);
        $this->assertSame([], $r->stream->luaMessages);
        $this->assertNull($r->endOfGame);
    }
}
