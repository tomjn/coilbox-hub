<?php

namespace Tests\Unit\Moderation;

use App\Domain\Moderation\Meters;
use App\Supabase\Supabase;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/assets/meters.test.ts`. The claim worth testing is that a
 * meter nothing can measure has no figure, never a zero, and cannot raise or
 * clear an alert.
 */
class MetersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.service_role_key' => 'service-key']);
    }

    /** @param list<array<string, mixed>> $usage */
    private function report(array $usage, ?int $bucketBytes = 0): array
    {
        Http::fake(function (Request $r) use ($usage, $bucketBytes) {
            if (str_ends_with($r->url(), 'staged_pictures_bucket_bytes')) {
                return $bucketBytes === null ? Http::response(['message' => 'no'], 400) : Http::response(json_encode($bucketBytes));
            }

            return Http::response($usage);
        });

        return Meters::fetch(Supabase::admin(), new DateTimeImmutable('2026-08-14T12:00:00Z'));
    }

    private static function meter(array $report, string $name): array
    {
        foreach ($report['meters'] as $meter) {
            if (str_starts_with($meter['name'], $name)) {
                return $meter;
            }
        }
        self::fail("no meter called {$name}");
    }

    public function test_a_render_angle_is_not_a_class_of_its_own(): void
    {
        $this->assertSame('render', Meters::assetClass('render:270'));
        $this->assertSame('render', Meters::assetClass('render:0'));
        $this->assertSame('buildpic', Meters::assetClass('buildpic'));
        $this->assertSame('overlay:height', Meters::assetClass('overlay:height'));
    }

    public function test_the_durable_tier_is_broken_down_by_class_biggest_first(): void
    {
        $this->assertSame([
            ['name' => 'render', 'objects' => 3, 'bytes' => 300],
            ['name' => 'buildpic', 'objects' => 9, 'bytes' => 90],
        ], Meters::durableClasses([
            ['tier' => 'static', 'variant' => 'render:0', 'objects' => 2, 'bytes' => 200],
            ['tier' => 'static', 'variant' => 'render:90', 'objects' => 1, 'bytes' => 100],
            ['tier' => 'static', 'variant' => 'buildpic', 'objects' => 9, 'bytes' => 90],
            ['tier' => 'bucket', 'variant' => 'buildpic', 'objects' => 4, 'bytes' => 4000],
        ]));
    }

    public function test_the_durable_tier_meter_counts_only_static_rows(): void
    {
        $report = $this->report([
            ['tier' => 'bucket', 'variant' => 'buildpic', 'objects' => 2, 'bytes' => 8192],
            ['tier' => 'static', 'variant' => 'minimap', 'objects' => 5, 'bytes' => 200000],
            ['tier' => 'orphan', 'variant' => 'superseded', 'objects' => 1, 'bytes' => 4096],
        ]);

        $this->assertSame(200000, self::meter($report, 'Durable tier')['used']);
        $this->assertSame([['name' => 'minimap', 'objects' => 5, 'bytes' => 200000]], $report['durable']);
        $this->assertSame('2026-08-14T12:00:00.000Z', $report['at']);
    }

    public function test_there_is_no_blob_meter_left(): void
    {
        $report = $this->report([]);

        $this->assertSame([], array_filter($report['meters'], fn ($m) => str_contains($m['name'], 'Blob')));
    }

    public function test_the_meters_nothing_here_can_see_say_so_instead_of_showing_a_zero(): void
    {
        $report = $this->report([]);

        foreach (['Supabase egress', 'Vercel fast data transfer', 'GitHub Pages bandwidth'] as $name) {
            $this->assertSame(['dashboard', null], [self::meter($report, $name)['basis'], self::meter($report, $name)['used']], $name);
        }
    }

    public function test_the_supabase_storage_meter_is_the_bucket_size(): void
    {
        $meter = self::meter($this->report([], 123456), 'Supabase Storage');

        $this->assertSame(123456, $meter['used']);
        $this->assertSame('counted', $meter['basis']);
        $this->assertSame(Meters::SUPABASE_STORAGE_ALLOWANCE_BYTES, $meter['allowance']);
    }

    public function test_a_bucket_size_that_could_not_be_read_is_not_zero(): void
    {
        $this->assertNull(self::meter($this->report([], null), 'Supabase Storage')['used']);
    }

    public function test_nothing_measurable_is_nothing_to_alert_on(): void
    {
        $this->assertNull(Meters::headroom(['name' => 'x', 'basis' => 'dashboard', 'used' => null, 'allowance' => 100, 'note' => '']));
        $this->assertSame([], Meters::headroomAlerts(['meters' => [
            ['name' => 'unmeasured', 'basis' => 'dashboard', 'used' => null, 'allowance' => 100, 'note' => ''],
            ['name' => 'roomy', 'basis' => 'counted', 'used' => 10, 'allowance' => 100, 'note' => ''],
        ]]));
    }

    public function test_a_counted_meter_three_quarters_full_fails_the_run(): void
    {
        $this->assertSame(['bucket: 1.5 KiB of 2.0 KiB, which is past 75% of the allowance.'], Meters::headroomAlerts(['meters' => [
            ['name' => 'bucket', 'basis' => 'counted', 'used' => 1536, 'allowance' => 2048, 'note' => ''],
            ['name' => 'roomy', 'basis' => 'counted', 'used' => 1535, 'allowance' => 2048, 'note' => ''],
        ]]));
    }

    public function test_bytes_are_read_in_the_units_the_allowances_are_written_in(): void
    {
        $this->assertSame('512 B', Meters::formatBytes(512));
        $this->assertSame('1.0 KiB', Meters::formatBytes(1024));
        $this->assertSame('1.0 GiB', Meters::formatBytes(1024 * 1024 * 1024));
    }
}
