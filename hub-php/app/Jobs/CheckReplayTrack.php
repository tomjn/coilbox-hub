<?php

namespace App\Jobs;

use App\Domain\Replays\TrackChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks an uploaded replay track against its replay (#665). Queued once a
 * mirror reports holding the file. The rules are in {@see TrackChecks}.
 */
class CheckReplayTrack implements ShouldQueue
{
    use Queueable;

    /** As {@see CheckReplay}: a mirror that is down gets three tries. */
    public int $tries = 3;

    /** Under the database queue's `retry_after` of 90 s, as {@see CheckReplay}. */
    public int $timeout = 80;

    public function __construct(public readonly string $gameId, public readonly int $formatVersion) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 540];
    }

    public function handle(): void
    {
        TrackChecks::check($this->gameId, $this->formatVersion);
    }

    /** After the last try the track is refused, so another sender can take its place. */
    public function failed(?Throwable $e): void
    {
        TrackChecks::failed($this->gameId, $this->formatVersion, $e?->getMessage() ?? 'no answer');
    }
}
