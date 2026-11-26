<?php

namespace App\Jobs;

use App\Domain\Replays\ReplayChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks an uploaded replay against its file before the hub shows it (#663).
 * Queued once a mirror reports holding the file (#662). The rules are in
 * {@see ReplayChecks}.
 */
class CheckReplay implements ShouldQueue
{
    use Queueable;

    /** A mirror that is down gets three tries, 60 and then 540 seconds apart. */
    public int $tries = 3;

    /**
     * The 52 minute, 16 player Beyond All Reason replay took 0.6 s and 61 MB
     * to check with the file on this machine, so nearly all of this is for the
     * fetch. It has to stay under the database queue's `retry_after` of 90 s,
     * or a second worker takes the job while it runs.
     */
    public int $timeout = 80;

    public function __construct(public readonly string $gameId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 540];
    }

    public function handle(): void
    {
        ReplayChecks::check($this->gameId);
    }

    /** After the last try the replay is refused, which frees its game id for another sender. */
    public function failed(?Throwable $e): void
    {
        ReplayChecks::failed($this->gameId, $e?->getMessage() ?? 'no answer');
    }
}
