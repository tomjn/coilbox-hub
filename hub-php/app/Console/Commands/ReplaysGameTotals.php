<?php

namespace App\Console\Commands;

use App\Domain\Replays\GameTotals;
use App\Domain\Replays\ReplayAnalyses;
use App\Domain\Replays\ReplayPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Counts replays into the game totals (#670): those listed before the totals
 * were kept, and those whose analysis was kept before it recorded units. Also
 * takes out any share whose replay is no longer listed.
 */
class ReplaysGameTotals extends Command
{
    protected $signature = 'replays:game-totals {game_id? : One replay to count again, else every one that needs it} {--dry-run : List the replays and write nothing}';

    protected $description = 'Count shared replays into the totals on each game page';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $old = DB::table('replay_analysis as a')->join('replay as r', 'r.game_id', '=', 'a.game_id')->where('r.state', 'listed')
            ->pluck('a.game_id')->reject(fn ($id) => isset(ReplayPage::facts($id)['by_unit']));
        if ($this->argument('game_id') !== null) {
            $ids = collect([(string) $this->argument('game_id')]);
        } else {
            $missing = DB::table('replay as r')->where('r.state', 'listed')->whereNotNull('r.game')
                ->whereNotExists(fn ($q) => $q->from('replay_game_share as s')->whereColumn('s.game_id', 'r.game_id'))->pluck('r.game_id');
            $stale = DB::table('replay_game_share as s')
                ->whereNotExists(fn ($q) => $q->from('replay as r')->whereColumn('r.game_id', 's.game_id')->where('r.state', 'listed'))->pluck('s.game_id');
            $ids = $missing->merge($stale)->merge($old)->unique()->sort()->values();
        }

        $failed = 0;
        foreach ($ids as $id) {
            if (! $dry) {
                // Working the facts out again counts the replay too.
                if (! $old->contains($id) || ! ReplayAnalyses::writeFacts($id)) {
                    if ($old->contains($id)) {
                        $this->error("{$id}: the analysis log does not read, so its units are not counted");
                        $failed++;
                    }
                    GameTotals::refresh($id);
                }
            }
            $this->line($id);
        }
        $this->line(($dry ? 'Would count ' : 'Counted ').$ids->count().($ids->count() === 1 ? ' replay.' : ' replays.'));
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
