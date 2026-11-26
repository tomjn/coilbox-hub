<?php

namespace Tests\Feature;

use App\Domain\Replays\ReplayException;
use App\Jobs\QueueTestJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class QueueTestJobTest extends TestCase
{
    use RefreshDatabase;

    private const REPLAY = __DIR__.'/../Fixtures/replays/sf-tumult.sdfz';

    public function test_the_command_queues_the_job(): void
    {
        Queue::fake();

        $this->artisan('jobs:test')->assertSuccessful();

        Queue::assertPushed(QueueTestJob::class, fn (QueueTestJob $job) => $job->replay === null);
    }

    public function test_the_command_queues_the_full_path_of_a_replay(): void
    {
        Queue::fake();

        $this->artisan('jobs:test', ['--replay' => self::REPLAY])->assertSuccessful();

        Queue::assertPushed(QueueTestJob::class, fn (QueueTestJob $job) => $job->replay === realpath(self::REPLAY));
    }

    public function test_the_command_refuses_a_replay_that_is_not_there(): void
    {
        Queue::fake();

        $this->artisan('jobs:test', ['--replay' => '/no/such.sdfz'])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_the_job_records_that_it_ran(): void
    {
        $this->travelTo(now()->startOfSecond());
        $job = new QueueTestJob(time() - 3);
        Log::shouldReceive('info')->once()->with('Queue test job ran.', Mockery::on(
            fn (array $context) => $context['waited_seconds'] >= 3
                && $context['release'] === base_path()
                && $context['memory_before_mb'] > 0
                && $context['peak_memory_mb'] > 0
                && ! array_key_exists('replay', $context)
        ));

        app()->call([$job, 'handle']);
    }

    public function test_the_job_reads_a_replay_and_records_it(): void
    {
        $job = new QueueTestJob(time(), realpath(self::REPLAY));
        Log::shouldReceive('info')->once()->with('Queue test job ran.', Mockery::on(
            fn (array $context) => $context['replay'] === realpath(self::REPLAY) && $context['packets'] > 0
        ));

        app()->call([$job, 'handle']);
    }

    public function test_a_job_that_fails_is_kept_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        QueueTestJob::dispatch(time(), __FILE__);

        Artisan::call('queue:work', ['--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringContainsString(ReplayException::class, DB::table('failed_jobs')->value('exception'));
    }
}
