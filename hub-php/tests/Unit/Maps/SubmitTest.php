<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Submit;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/maps/submit.test.ts`. */
class SubmitTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x', 'hub.supabase.service_role_key' => 'sb_secret_x']);
        Http::preventStrayRequests();
    }

    private static function entry(string $mapName): array
    {
        return [
            'map_name' => $mapName, 'display_name' => null, 'description' => null, 'map_version' => null, 'author' => null,
            'archive_filename' => null, 'source_archive' => 'comet_catcher_remake_1.8.sd7', 'source_hash' => 'src-comet',
            'catalog_version' => 3, 'width_elmos' => 6144, 'height_elmos' => 10240, 'world_height_min' => -120.5,
            'world_height_max' => 890, 'min_wind' => null, 'max_wind' => null, 'tidal_strength' => null, 'void_water' => null,
            'void_ground' => null, 'water_coverage' => null, 'appearance' => new \stdClass, 'points' => ['start' => [], 'metal' => [], 'geo' => []],
        ];
    }

    private static function submissions(string ...$names): array
    {
        return array_map(fn (string $name) => Submit::build(self::entry($name)), $names);
    }

    public function test_a_submission_carries_the_facts_both_slugs_and_the_digest(): void
    {
        $submission = Submit::build(self::entry('Comet Catcher Remake 1.8'));

        $this->assertSame('comet-catcher-remake-1-8', $submission['slug']);
        $this->assertMatchesRegularExpression('/^comet-catcher-remake-1-8-[0-9a-f]{8}$/', $submission['slug_alternative']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $submission['facts_digest']);
        $this->assertSame('Comet Catcher Remake 1.8', $submission['entry']['map_name']);
    }

    public function test_neither_slug_is_part_of_what_the_digest_covers(): void
    {
        $submission = Submit::build(self::entry('Comet Catcher Remake 1.8'));

        $this->assertStringNotContainsString($submission['slug'], json_encode($submission['entry']));
        $this->assertStringNotContainsString($submission['facts_digest'], json_encode($submission['entry']));
    }

    public function test_a_batch_is_sent_in_a_settled_order(): void
    {
        Http::fake(['sb.test/rest/v1/rpc/submit_map_facts' => Http::response([])]);

        Submit::write(Supabase::admin(), self::submissions('Zed 1.0', 'Alpha 1.0', 'Middle 1.0'), self::USER);

        Http::assertSent(function (Request $request) {
            $this->assertSame(self::USER, $request['p_submitted_by']);
            $this->assertSame(['Alpha 1.0', 'Middle 1.0', 'Zed 1.0'], array_map(fn ($one) => $one['entry']['map_name'], $request['p_maps']));
            // An empty appearance goes to the function as an object, not a list.
            $this->assertStringContainsString('"appearance":{}', $request->body());

            return true;
        });
    }

    public function test_the_outcomes_come_back_keyed_by_name(): void
    {
        Http::fake(['sb.test/rest/v1/rpc/submit_map_facts' => Http::response([
            ['map_name' => 'Alpha 1.0', 'outcome' => 'stored', 'said' => null],
            ['map_name' => 'Zed 1.0', 'outcome' => 'conflict', 'said' => null],
        ])]);

        $written = Submit::write(Supabase::admin(), self::submissions('Zed 1.0', 'Alpha 1.0'), self::USER);

        $this->assertTrue($written['ok']);
        $this->assertSame(['outcome' => 'stored', 'said' => null], $written['outcomes']['Alpha 1.0']);
        $this->assertSame(['outcome' => 'conflict', 'said' => null], $written['outcomes']['Zed 1.0']);
    }

    public function test_a_rate_limited_batch_is_told_apart_from_a_database_that_is_down(): void
    {
        Http::fake(['sb.test/rest/v1/rpc/submit_map_facts' => Http::sequence()
            ->push(['code' => '53400', 'message' => 'rate limited'], 400)
            ->push(['code' => '57P01', 'message' => 'down'], 503)]);

        $this->assertSame(['ok' => false, 'rateLimited' => true], Submit::write(Supabase::admin(), self::submissions('Alpha 1.0'), self::USER));
        $this->assertSame(['ok' => false, 'rateLimited' => false], Submit::write(Supabase::admin(), self::submissions('Alpha 1.0'), self::USER));
    }
}
