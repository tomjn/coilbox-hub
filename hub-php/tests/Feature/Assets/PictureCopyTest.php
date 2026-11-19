<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\PictureCopy;
use App\Supabase\Local\PictureStore;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** `php artisan pictures:copy`: the dry run, the live copy and its switch (#598). */
class PictureCopyTest extends TestCase
{
    use RefreshDatabase;

    private const ASSET = 'aaaaaaaa-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.pictures_store' => null,
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'hub.supabase.timeout' => 0,
        ]);
        Storage::fake('staged');
    }

    /**
     * Supabase with one picture, one event and two staged objects in two
     * folders. `$downloaded` is what an object download answers.
     */
    private function fakeSupabase(int $assets = 1, ?string $downloaded = null): void
    {
        $asset = [
            'id' => self::ASSET, 'game' => 'BYAR', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic',
            'source_hash' => 'raw', 'hash' => 'h', 'encode_profile' => 'p', 'path' => 'units/BYAR/a.webp', 'origin' => 'extracted',
            'tier' => 'bucket', 'mime' => 'image/webp', 'bytes' => 5, 'width' => 1, 'height' => 1, 'map_width' => null,
            'map_height' => null, 'source_archive' => 'a.sdz', 'seen_at' => '2026-11-01T10:00:00.5+00:00', 'promoted_at' => null,
            'uploaded_by' => null, 'moderation' => 'approved', 'approval_source' => 'seed', 'created_at' => '2026-11-01T10:00:00+00:00',
            'updated_at' => '2026-11-01T10:00:00+00:00', 'world_height_min' => 12.3, 'world_height_max' => 812,
            'rejection_kind' => null, 'blob_path' => null, 'blob_path_tier' => null, 'bytes_missing_at' => null, 'faction_key' => null,
        ];
        $event = ['id' => 4, 'asset_id' => self::ASSET, 'action' => 'seeded', 'rejection_kind' => null, 'actor' => null, 'uploader' => null,
            'at' => '2026-11-01T10:00:00+00:00', 'uploader_display_name' => null, 'uploader_provider_name' => null];
        $file = fn (string $name, int $size) => ['name' => $name, 'id' => 'x', 'created_at' => '2026-11-01T10:00:00.000Z',
            'updated_at' => '2026-11-01T10:00:00.000Z', 'metadata' => ['size' => $size, 'mimetype' => 'image/webp']];
        $folder = fn (string $name) => ['name' => $name, 'id' => null, 'metadata' => null];

        Http::fake(function (Request $request) use ($asset, $event, $assets, $file, $folder, $downloaded) {
            $url = urldecode($request->url());
            if (str_contains($url, '/storage/v1/object/list/')) {
                return Http::response(match ($request->data()['prefix']) {
                    '' => [$folder('units'), $file('top.webp', 3)],
                    'units' => [$folder('BYAR')],
                    'units/BYAR' => [$file('a.webp', 5)],
                    default => [],
                });
            }
            if (str_contains($url, '/storage/v1/object/')) {
                return Http::response($downloaded ?? (str_ends_with($url, 'a.webp') ? 'abcde' : 'xyz'));
            }
            preg_match('#/rest/v1/([a-z_]+)#', $url, $m);
            $table = $m[1];
            if ($request->method() === 'HEAD') {
                return Http::response('', 200, ['Content-Range' => '*/'.(['asset' => $assets, 'asset_event' => 1][$table] ?? 0)]);
            }
            $rows = str_contains($url, 'offset=0') ? (['asset' => [$asset], 'asset_event' => [$event]][$table] ?? []) : [];

            return Http::response(json_encode($rows));
        });
    }

    public function test_the_dry_run_reports_counts_and_room_and_writes_nothing(): void
    {
        $this->fakeSupabase();

        $this->artisan('pictures:copy', ['--dry-run' => true])
            ->expectsOutput('asset: Supabase 1, MariaDB 0')
            ->expectsOutput('asset_event: Supabase 1, MariaDB 0')
            ->expectsOutput('staged-pictures: Supabase 2 objects, 8 bytes. Disk 0 objects, 0 bytes.')
            ->expectsOutput('Dry run: nothing was written.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('asset')->count());
        $this->assertSame([], Storage::disk('staged')->allFiles());
        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && $r->method() !== 'HEAD' && ! str_contains($r->url(), '/object/list/'));
    }

    public function test_the_copy_keeps_rows_and_objects_then_switches_and_reads_locally(): void
    {
        $this->fakeSupabase();

        $this->artisan('pictures:copy')
            ->expectsOutput('staged-pictures: Supabase 2 objects, 8 bytes. Disk the same.')
            ->expectsOutput('The hub now reads and writes pictures in MariaDB, and staged pictures on this disk.')
            ->assertExitCode(0);

        $this->assertSame(PictureStore::MARIADB, PictureStore::fresh());
        $this->assertSame('abcde', Storage::disk('staged')->get('units/BYAR/a.webp'));
        $this->assertSame('xyz', Storage::disk('staged')->get('top.webp'));
        $this->assertSame(4, (int) DB::table('asset_event')->value('id'));

        Http::fake();
        $row = Supabase::anon()->from('asset')->select('seen_at,world_height_min,world_height_max')->maybeSingle();
        $this->assertSame(['seen_at' => '2026-11-01T10:00:00.5+00:00', 'world_height_min' => 12.3, 'world_height_max' => 812], $row);
        $this->assertSame('abcde', Supabase::admin()->downloadObject('staged-pictures', 'units/BYAR/a.webp'));
        Http::assertNothingSent();
    }

    public function test_a_count_that_does_not_match_switches_back(): void
    {
        $this->fakeSupabase(assets: 2);

        $this->artisan('pictures:copy')->assertExitCode(1);

        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
        $this->assertSame(0, DB::table('asset')->count());
    }

    public function test_an_object_whose_size_does_not_match_stops_the_copy_before_the_freeze(): void
    {
        $this->fakeSupabase(downloaded: 'too long by far');

        $this->artisan('pictures:copy')->assertExitCode(1);

        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
    }

    public function test_too_little_room_stops_the_copy_before_anything_is_written(): void
    {
        $this->fakeSupabase();
        $said = [];
        $copy = new PictureCopy(Supabase::admin()->remote(), function (string $line) use (&$said) {
            $said[] = $line;
        }, fn () => null, fn () => 7.0);

        try {
            $copy->run();
            $this->fail('Expected the copy to stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('needs 8 bytes', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('staged')->allFiles());
        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
    }

    public function test_copying_again_after_the_switch_is_refused(): void
    {
        PictureStore::set(PictureStore::MARIADB);

        $this->artisan('pictures:copy')->assertExitCode(1);
        $this->artisan('pictures:store', ['state' => 'supabase'])->assertExitCode(0);
        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
    }
}
