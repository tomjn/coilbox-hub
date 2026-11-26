<?php

namespace App\Jobs;

use App\Domain\Replays\ReplayAnalyses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks a replay analysis from Coilbox against the replay's final totals
 * (#664). The rules are in {@see ReplayAnalyses}.
 */
class CheckReplayAnalysis implements ShouldQueue
{
    use Queueable;

    /** As {@see CheckReplay}. Nothing is fetched, so a retry is only for a database that failed. */
    public int $tries = 3;

    /** Under the database queue's `retry_after` of 90 s, as {@see CheckReplay}. */
    public int $timeout = 80;

    public function __construct(public readonly int $submissionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 540];
    }

    public function handle(): void
    {
        ReplayAnalyses::check($this->submissionId);
    }

    public function failed(?Throwable $e): void
    {
        ReplayAnalyses::failed($this->submissionId, $e?->getMessage() ?? 'no answer');
    }
}
