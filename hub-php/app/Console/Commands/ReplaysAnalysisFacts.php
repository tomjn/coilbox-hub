<?php

namespace App\Console\Commands;

use App\Domain\Replays\AnalysisFacts;
use App\Domain\Replays\ReplayAnalyses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Works out what the replay page draws from each kept analysis (#668), for
 * analyses kept before those facts were stored or stored in an older shape.
 */
class ReplaysAnalysisFacts extends Command
{
    protected $signature = 'replays:analysis-facts {game_id? : One replay, else every analysis that needs it} {--dry-run : List the analyses and write nothing}';

    protected $description = 'Fill in the replay page facts of kept analyses from their logs';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $query = DB::table('replay_analysis')->orderBy('game_id');
        if ($this->argument('game_id') !== null) {
            $query->where('game_id', (string) $this->argument('game_id'));
        }

        $written = $failed = 0;
        foreach ($query->get(['game_id', 'facts']) as $row) {
            $facts = json_decode((string) $row->facts, true);
            if ($this->argument('game_id') === null && ($facts['v'] ?? null) === AnalysisFacts::VERSION) {
                continue;
            }
            if ($dry) {
                $this->line($row->game_id);
                $written++;

                continue;
            }
            if (ReplayAnalyses::writeFacts($row->game_id)) {
                $this->line("{$row->game_id}: written");
                $written++;
            } else {
                $this->error("{$row->game_id}: the log does not read or the replay is gone");
                $failed++;
            }
        }
        $this->line(($dry ? 'Would write ' : 'Wrote ').$written.($written === 1 ? ' analysis' : ' analyses').($failed > 0 ? ", {$failed} failed" : '').'.');
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
