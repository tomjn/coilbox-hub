<?php

namespace Tests\Feature\Engines;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** What a mirror report does for engines: the takes_engines flag and the lack of points. */
class EngineReportTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const ARCHIVE = 'dddddddd-0000-4000-8000-000000000001';

    private const HELD = '58648994035e7d2b82ee74b1aa5a66e772655bddf03156fc4ee439a1b8872ef2';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'hub.mirrors.reservation' => 86400,
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_engines' => false, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function report(array $over = []): TestResponse
    {
        $payload = json_encode($over + [
            'v' => 1, 'mirror' => 'jj-darkstars', 'event' => 'sync', 'upload' => null, 'sent_at' => 1794787260,
            'catalogue_seq' => null, 'catalogue_generated_at' => null, 'held' => [], 'unknown' => [],
        ]);
        $sig = Signing::sign(Signing::CTX_REPORT, $payload, B64::decode(self::fixtureKeys()['mirror_secret_key']));

        return $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['payload' => B64::encode($payload), 'sig' => B64::encode($sig)]));
    }

    /** @param array<string, mixed> $over */
    private function uploading(array $over = []): void
    {
        $filename = $over['filename'] ?? 'Comet Catcher 1.8.sd7';
        DB::table('archive')->insert($over + [
            'id' => self::ARCHIVE, 'sha256' => self::HELD, 'filename' => $filename, 'filename_lower' => mb_strtolower($filename),
            'kind' => 'map', 'internal_name' => 'Comet Catcher 1.8', 'version' => '', 'shortname' => null, 'author' => 'Beherith',
            'description' => null, 'size' => 43, 'uploader' => self::USER, 'state' => 'uploading', 'game_id' => null,
            'upload_mirror_id' => 'jj-darkstars', 'reserved_until' => '2099-01-01 00:00:00.000000', 'listed_at' => null,
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 09:00:00.000000',
        ]);
    }

    private function points(): int
    {
        return CommunityPoints::total(self::USER);
    }

    public function test_a_report_with_engine_in_kinds_sets_takes_engines(): void
    {
        $this->supabase(['HEAD mirror.test/' => Http::response('', 200)]);

        $this->report(['kinds' => ['map', 'engine']])->assertOk();

        $this->assertTrue((bool) DB::table('mirror')->value('takes_engines'));
    }

    public function test_a_report_without_kinds_clears_takes_engines(): void
    {
        DB::table('mirror')->update(['takes_engines' => true]);
        $this->supabase(['mirror.test/listing.json' => Http::response('{}', 200)]);

        $this->report()->assertOk();
        $this->assertFalse((bool) DB::table('mirror')->value('takes_engines'));

        DB::table('mirror')->update(['takes_engines' => true]);
        $this->report(['kinds' => ['map', 'game'], 'sent_at' => 1794787261])->assertOk();
        $this->assertFalse((bool) DB::table('mirror')->value('takes_engines'));
    }

    public function test_listing_an_engine_awards_no_points(): void
    {
        $this->uploading([
            'kind' => 'engine', 'internal_name' => 'recoil', 'version' => '2026.09.03', 'platform' => 'arm64-macos',
            'filename' => 'recoil_2026.09.03_arm64-macos.7z',
        ]);
        $this->supabase(['HEAD mirror.test/engines/' => Http::response('', 200, ['Content-Length' => '43'])]);

        $this->report(['event' => 'upload', 'upload' => 'up-1', 'held' => [self::HELD], 'kinds' => ['engine']])->assertOk();

        $this->assertSame('listed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
        $this->assertSame(0, $this->points());
    }

    public function test_listing_a_map_still_awards_points(): void
    {
        $this->uploading();
        $this->supabase(['HEAD mirror.test/maps/' => Http::response('', 200, ['Content-Length' => '43'])]);

        $this->report(['event' => 'upload', 'upload' => 'up-1', 'held' => [self::HELD]])->assertOk();

        $this->assertSame('listed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
        $this->assertSame(20, $this->points());
    }
}
