<?php

namespace App\Jobs;

use App\Domain\Replays\ReplayReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Shows that a queue worker is running, and which release it runs. Given a
 * replay it reads it, so the worker's memory can be measured on real work.
 */
class QueueTestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $dispatchedAt, public readonly ?string $replay = null) {}

    public function handle(ReplayReader $reader): void
    {
        $context = [
            'waited_seconds' => time() - $this->dispatchedAt,
            'release' => base_path(),
            'pid' => getmypid(),
            // What earlier jobs left behind, which `queue:work --memory` compares.
            'memory_before_mb' => $this->megabytes(memory_get_usage(true)),
        ];
        memory_reset_peak_usage();
        if ($this->replay !== null) {
            $replay = $reader->read($this->replay);
            // Orders are decoded as they are read, so a real job pays for this too.
            iterator_count($replay->stream->orders);
            $context['replay'] = $this->replay;
            $context['packets'] = $replay->stream->packets;
        }
        $context['peak_memory_mb'] = $this->megabytes(memory_get_peak_usage(true));

        Log::channel('queue-test')->info('Queue test job ran.', $context);
    }

    private function megabytes(int $bytes): float
    {
        return round($bytes / 1048576, 1);
    }
}
