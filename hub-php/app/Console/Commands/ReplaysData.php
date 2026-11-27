<?php

namespace App\Console\Commands;

use App\Domain\Replays\ReplayData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Writes this week's downloadable replay files (#672). The scheduler runs it every Monday. */
class ReplaysData extends Command
{
    protected $signature = 'replays:data {--dry-run : Say what would be written and write nothing}';

    protected $description = 'Write the listed replays to CSV files for /replays/data';

    public function handle(): int
    {
        $week = gmdate('Y-m-d');
        $folder = ReplayData::root().'/'.$week;
        if ($this->option('dry-run')) {
            $this->line(sprintf('Would write %d listed replays to %s, and keep the newest %d sets.',
                DB::table('replay')->where('state', 'listed')->count(), $folder, ReplayData::KEEP));
            $this->line('Dry run: nothing was written.');

            return self::SUCCESS;
        }
        foreach (ReplayData::write($week) as $file => $rows) {
            $this->line(sprintf('%s/%s.csv: %d rows', $folder, $file, $rows));
        }

        return self::SUCCESS;
    }
}
