<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks an uploaded replay against its file before the hub shows it. Queued
 * once a mirror reports holding the file (#662). The replay stays in the
 * `checking` state, and so is not shown, until #663 writes the check here.
 */
class CheckReplay implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $gameId) {}

    public function handle(): void {}
}
