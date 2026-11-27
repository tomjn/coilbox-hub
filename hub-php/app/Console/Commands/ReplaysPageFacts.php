<?php

namespace App\Console\Commands;

use App\Domain\Replays\ReplayChecks;
use App\Domain\Replays\ReplayException;
use App\Domain\Replays\ReplayPageFacts;
use App\Domain\Replays\ReplayReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads listed replays' files again for what the replay page draws (#667):
 * replays checked before the page's facts were kept, and replays with no order
 * heat because the catalogue did not have their map then.
 */
class ReplaysPageFacts extends Command
{
    protected $signature = 'replays:page-facts {game_id? : One replay, else every one that needs it} {--dry-run : List the replays and write nothing}';

    protected $description = 'Fill in the replay page facts of listed replays from their files';

    public function handle(ReplayReader $reader): int
    {
        $dry = (bool) $this->option('dry-run');
        $query = DB::table('replay')->where('state', 'listed')->orderBy('game_id');
        if ($this->argument('game_id') !== null) {
            $query->where('game_id', (string) $this->argument('game_id'));
        } else {
            $query->where(fn ($q) => $q->whereNull('page_facts_at')->orWhereNull('order_heat'));
        }

        $written = $failed = 0;
        foreach ($query->get() as $row) {
            $mapSize = ReplayPageFacts::mapSize((string) $row->map);
            // Read again only for something new: heat needs the map's size.
            if ($row->page_facts_at !== null && $row->order_heat === null && $mapSize === null && $this->argument('game_id') === null) {
                continue;
            }
            if ($dry) {
                $this->line("{$row->game_id} {$row->map}".($mapSize === null ? ' (no map size, so no heat)' : ''));
                $written++;

                continue;
            }
            try {
                $bytes = ReplayChecks::fetch($row);
                if (hash('sha256', $bytes) !== $row->sha256) {
                    throw new RuntimeException('The mirror gave a different file.');
                }
                ReplayPageFacts::write($row->game_id, $reader->readBytes($bytes), $mapSize);
                $this->line("{$row->game_id}: written".($mapSize === null ? ', no heat without the map size' : ''));
                $written++;
            } catch (RuntimeException|ReplayException $e) {
                $this->error("{$row->game_id}: {$e->getMessage()}");
                $failed++;
            }
        }
        $this->line(($dry ? 'Would write ' : 'Wrote ').$written.($written === 1 ? ' replay' : ' replays').($failed > 0 ? ", {$failed} failed" : '').'.');
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
