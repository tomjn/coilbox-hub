<?php

namespace App\Console\Commands;

use App\Jobs\QueueTestJob;
use Illuminate\Console\Command;

class JobsTest extends Command
{
    protected $signature = 'jobs:test {--replay= : A replay file the job reads, to measure the memory real work needs}';

    protected $description = 'Queue a job that logs that the worker ran it';

    public function handle(): int
    {
        $replay = $this->option('replay');
        if ($replay !== null) {
            // The worker runs in another folder, so a relative path would miss.
            $path = realpath($replay);
            if ($path === false) {
                $this->error("There is no file at {$replay}.");

                return self::FAILURE;
            }
            $replay = $path;
        }

        QueueTestJob::dispatch(time(), $replay);

        $this->line('Queued. The worker logs "Queue test job ran." in storage/logs/queue-test.log when it runs it.');

        return self::SUCCESS;
    }
}
