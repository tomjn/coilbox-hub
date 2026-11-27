<?php

namespace App\Console\Commands;

use App\Domain\Replays\MapTotals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Counts replays into the map totals (#669): those listed before the totals
 * were kept, and takes out any share whose replay is no longer listed.
 */
class ReplaysMapTotals extends Command
{
    protected $signature = 'replays:map-totals {game_id? : One replay to count again, else every one that needs it} {--dry-run : List the replays and write nothing}';

    protected $description = 'Count shared replays into the totals on each map page';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if ($this->argument('game_id') !== null) {
            $ids = collect([(string) $this->argument('game_id')]);
        } else {
            $missing = DB::table('replay as r')->where('r.state', 'listed')->whereNotNull('r.map')
                ->whereNotExists(fn ($q) => $q->from('replay_map_share as s')->whereColumn('s.game_id', 'r.game_id'))->pluck('r.game_id');
            $stale = DB::table('replay_map_share as s')
                ->whereNotExists(fn ($q) => $q->from('replay as r')->whereColumn('r.game_id', 's.game_id')->where('r.state', 'listed'))->pluck('s.game_id');
            $ids = $missing->merge($stale)->sort()->values();
        }

        foreach ($ids as $id) {
            if (! $dry) {
                MapTotals::refresh($id);
            }
            $this->line($id);
        }
        $this->line(($dry ? 'Would count ' : 'Counted ').$ids->count().($ids->count() === 1 ? ' replay.' : ' replays.'));
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
