<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Caps;
use App\Domain\Assets\Upload;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/assets/upload.test.ts`. PostgREST is faked by URL: the
 * identity read carries `or=`, the hourly count carries `uploaded_by=`, the
 * render count is any other count on `asset`, and `game` is the faction check.
 */
class UploadTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';

    private const HASH = 'encabc';

    /** @var list<string> every request URL, for asserting what was and was not asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'sb_secret_test',
        ]);
    }

    private function declaration(array $over = []): array
    {
        return [...[
            'identity' => AssetResolver::unit('BYAR', 'armsolar'),
            'sourceHash' => 'raw-abc', 'encodeProfile' => 'webp-lossless-256', 'origin' => 'extracted',
            'mime' => 'image/webp', 'bytes' => 4096, 'mapWidth' => null, 'mapHeight' => null,
            'worldHeightMin' => null, 'worldHeightMax' => null, 'sourceArchive' => 'byar_1.2.sdz',
        ], ...$over];
    }

    private function held(array $over = []): array
    {
        return [...['id' => 'held', 'source_hash' => 'raw-old', 'source_archive' => 'byar_1.1.sdz', 'uploaded_by' => self::USER, 'moderation' => 'approved', 'bytes' => 4096, 'bytes_missing_at' => null], ...$over];
    }

    /** @param array{existing?: array|null, unitRenders?: int, accountBytes?: int, recent?: int, factionExists?: bool, broken?: string, capabilities?: list<string>} $world */
    private function fake(array $world = []): void
    {
        $world = [...['existing' => null, 'unitRenders' => 0, 'accountBytes' => 0, 'recent' => 0, 'factionExists' => true, 'broken' => null, 'capabilities' => []], ...$world];

        // A fresh factory, so a second world in one test replaces the first rather than queueing behind it.
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($world) {
            $url = urldecode($request->url());
            $this->asked[] = $request->method().' '.$url;
            $count = fn (int $n) => Http::response($n > 0 ? [['id' => 'x']] : [], 200, ['Content-Range' => ($n > 0 ? '0-0' : '*').'/'.$n]);

            if (str_contains($url, '/rpc/account_asset_bytes')) {
                return $world['broken'] === 'bytes' ? Http::response(['message' => 'down'], 500) : Http::response(json_encode($world['accountBytes']), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($url, '/rpc/has_capability')) {
                return Http::response(json_encode(in_array($request->data()['capability'], $world['capabilities'], true)), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($url, '/rest/v1/game?')) {
                return $count($world['factionExists'] ? 1 : 0);
            }
            if ($request->method() === 'GET' && str_contains($url, '/rest/v1/asset?')) {
                if (str_contains($url, 'or=')) {
                    if ($world['broken'] === 'identity') {
                        return Http::response(['message' => 'down'], 500);
                    }

                    return Http::response($world['existing'] === null ? [] : [$world['existing']]);
                }
                if (str_contains($url, 'uploaded_by=')) {
                    return $count($world['recent']);
                }

                return $count($world['unitRenders']);
            }

            return Http::response([['id' => 'new-row']], 201);
        });
    }

    private function check(array $world = [], array $over = [], ?\Closure $mayReplace = null): array
    {
        $this->fake($world);

        return Upload::check(Supabase::admin(), self::USER, $this->declaration($over), self::HASH, $mayReplace);
    }

    private function refusal(array $result): string
    {
        $this->assertFalse($result['ok']);

        return $result['status'].' '.$result['error'];
    }

    public function test_a_declaration_that_clears_everything_comes_back_with_its_path(): void
    {
        $this->assertSame(['ok' => true, 'path' => 'units/BYAR/buildpic/encabc.webp', 'replacing' => null, 'conflict' => null], $this->check());
    }

    public function test_pure_refusals_never_ask_the_database(): void
    {
        $this->assertSame('415 `mime` must be one of image/webp, image/png.', $this->refusal($this->check([], ['mime' => 'image/gif'])));
        $this->assertSame('413 A "buildpic" may be at most 262144 bytes. That one declares 262145.', $this->refusal($this->check([], ['bytes' => 262145])));
        $this->assertSame(
            '400 `game` and `variant` have to be storable as path segments: letters, digits, dots, dashes and underscores, and a variant may separate segments with a colon.',
            $this->refusal($this->check([], ['identity' => AssetResolver::unit('../x', 'armsolar')])),
        );
        $this->assertSame([], $this->asked);
    }

    public function test_the_object_cap_is_the_classs_or_the_backstop(): void
    {
        $this->assertSame(Caps::all()['minimap']['maxBytes'], 512 * 512 * 4);
        $this->assertTrue($this->check([], ['identity' => AssetResolver::map('Comet', 'minimap'), 'bytes' => 512 * 512 * 4])['ok']);
        $this->assertSame('413 A "overlay:metal" may be at most 2097152 bytes. That one declares 2097153.', $this->refusal($this->check([], ['identity' => AssetResolver::map('Comet', 'overlay:metal'), 'bytes' => 2097153])));
    }

    public function test_the_licence_table_is_never_asked(): void
    {
        $this->check();
        $this->assertEmpty(array_filter($this->asked, fn ($u) => str_contains($u, 'asset_licence')));
    }

    public function test_replacement_rules(): void
    {
        $this->assertSame(['ok' => true, 'path' => 'units/BYAR/buildpic/encabc.webp', 'replacing' => 'held', 'conflict' => null], $this->check(['existing' => $this->held()]));
        $this->assertSame('409 Another account uploaded the asset with that identity, so it cannot be replaced.', $this->refusal($this->check(['existing' => $this->held(['uploaded_by' => 'someone-else'])])));
        $this->assertSame('409 Another account uploaded the asset with that identity, so it cannot be replaced.', $this->refusal($this->check(['existing' => $this->held(['uploaded_by' => null])])));
        $this->assertSame('409 That asset was rejected, and a rejection is not something an upload can undo.', $this->refusal($this->check(['existing' => $this->held(['moderation' => 'rejected'])])));
        $this->assertSame(
            '409 The hub already holds that asset with the same `source_hash`. Check `/api/v1/assets/have` before uploading.',
            $this->refusal($this->check(['existing' => $this->held(['source_hash' => 'raw-abc'])])),
        );
        // The hub lost the bytes, so the same source bytes are exactly what is wanted.
        $this->assertTrue($this->check(['existing' => $this->held(['source_hash' => 'raw-abc', 'bytes_missing_at' => '2026-09-14T12:00:00Z'])])['ok']);
        $this->assertSame('409 That asset was rejected, and a rejection is not something an upload can undo.', $this->refusal($this->check(['existing' => $this->held(['moderation' => 'rejected', 'bytes_missing_at' => '2026-09-14T12:00:00Z'])])));
    }

    public function test_source_conflicts_ride_along(): void
    {
        $this->assertNull($this->check(['existing' => $this->held()])['conflict']);

        $accepted = $this->check(['existing' => $this->held(['source_archive' => 'byar_1.2.sdz'])]);
        $this->assertTrue($accepted['ok']);
        $this->assertSame(['assetId' => 'held', 'sourceArchive' => 'byar_1.2.sdz', 'heldSourceHash' => 'raw-old', 'reportedSourceHash' => 'raw-abc', 'reportedBy' => self::USER], $accepted['conflict']);

        $refused = $this->check(['existing' => $this->held(['source_archive' => 'byar_1.2.sdz', 'uploaded_by' => 'stranger'])]);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('held', $refused['conflict']['assetId']);
    }

    public function test_the_render_ceiling(): void
    {
        $render = ['identity' => AssetResolver::unit('BYAR', 'armsolar', 'render:315')];
        $this->assertSame('409 That unit already has 8 stored renders, which is the ceiling of 8.', $this->refusal($this->check(['unitRenders' => 8], $render)));
        $this->assertTrue($this->check(['unitRenders' => 8, 'existing' => $this->held()], $render)['ok']);
        $this->assertTrue($this->check(['unitRenders' => 8])['ok']);
        $this->assertTrue($this->check(['unitRenders' => 8], ['identity' => AssetResolver::map('Comet')])['ok']);

        $this->fake();
        Upload::check(Supabase::admin(), self::USER, $this->declaration($render), self::HASH);
        $this->assertNotEmpty(array_filter($this->asked, fn ($u) => str_contains($u, 'variant=like.render:%') && str_contains($u, 'unit_name=eq.armsolar')));
    }

    public function test_the_account_quota(): void
    {
        $quota = Upload::ACCOUNT_STORAGE_QUOTA_BYTES;
        $this->assertTrue($this->check(['accountBytes' => $quota - 4096])['ok']);
        $this->assertSame('413 That upload would put this account over its 67108864 byte storage quota.', $this->refusal($this->check(['accountBytes' => $quota - 4095])));
        // A replacement is charged the difference.
        $this->assertTrue($this->check(['accountBytes' => $quota, 'existing' => $this->held()])['ok']);
        // A takeover is charged the whole object.
        $this->assertSame(413, $this->check(['accountBytes' => $quota, 'existing' => $this->held(['uploaded_by' => 'other'])], [], fn () => true)['status']);
    }

    public function test_the_hourly_limit(): void
    {
        $this->assertTrue($this->check(['recent' => 499])['ok']);
        $this->assertSame('429 Too many uploads for that subject in the last hour, which is capped at 500. Try again later.', $this->refusal($this->check(['recent' => 500])));

        $this->fake();
        Upload::check(Supabase::admin(), self::USER, $this->declaration(), self::HASH);
        $this->assertNotEmpty(array_filter($this->asked, fn ($u) => str_contains($u, 'uploaded_by=eq.'.self::USER) && str_contains($u, 'game=eq.BYAR') && str_contains($u, 'seen_at=gte.')));
    }

    public function test_a_quota_that_cannot_be_read_refuses(): void
    {
        $this->assertSame('503 The upload quotas could not be read just now. Try again shortly.', $this->refusal($this->check(['broken' => 'bytes'])));
        $this->assertSame('503 The upload quotas could not be read just now. Try again shortly.', $this->refusal($this->check(['broken' => 'identity'])));
    }

    public function test_two_limits_always_report_the_same_one(): void
    {
        $world = ['unitRenders' => 8, 'accountBytes' => Upload::ACCOUNT_STORAGE_QUOTA_BYTES, 'recent' => 500];
        $this->assertSame(409, $this->check($world, ['identity' => AssetResolver::unit('BYAR', 'armsolar', 'render:1')])['status']);
        $this->assertSame(413, $this->check($world)['status']);
    }

    public function test_faction_logos(): void
    {
        $logo = ['identity' => AssetResolver::faction('BYAR', 'armada'), 'bytes' => 3000];
        $this->assertSame(['ok' => true, 'path' => 'factions/BYAR/logo/encabc.webp', 'replacing' => null, 'conflict' => null], $this->check([], $logo));

        $this->asked = [];
        $this->assertSame('404 The game "BYAR" has no faction with the key "armada".', $this->refusal($this->check(['factionExists' => false], $logo)));
        $this->assertCount(1, $this->asked);
        $this->assertStringContainsString('shortname=eq.BYAR', $this->asked[0]);
        $this->assertStringContainsString('game_faction.key=eq.armada', $this->asked[0]);

        $this->assertSame('413 A "logo" may be at most 65536 bytes. That one declares 65537.', $this->refusal($this->check([], [...$logo, 'bytes' => 65537])));

        $this->asked = [];
        $this->check([], $logo);
        $this->assertNotEmpty(array_filter($this->asked, fn ($u) => str_contains($u, 'uploaded_by=') && str_contains($u, 'faction_key=not.is.null')));

        $this->asked = [];
        $this->check();
        $this->assertEmpty(array_filter($this->asked, fn ($u) => str_contains($u, '/rest/v1/game')));
    }

    public function test_replace_any_asset(): void
    {
        $asked = 0;
        $may = function () use (&$asked) {
            $asked++;

            return true;
        };
        $this->assertSame('held', $this->check(['existing' => $this->held(['uploaded_by' => 'other'])], [], $may)['replacing']);
        $this->assertSame(1, $asked);

        $this->check(['existing' => $this->held()], [], $may);
        $this->assertSame(1, $asked, 'asked only when the picture is somebody else\'s');

        $this->assertSame(409, $this->check(['existing' => $this->held(['uploaded_by' => 'other', 'moderation' => 'rejected'])], [], $may)['status']);
        $this->assertSame(409, $this->check(['existing' => $this->held(['uploaded_by' => 'other', 'source_hash' => 'raw-abc'])], [], $may)['status']);
        $this->assertSame(429, $this->check(['existing' => $this->held(['uploaded_by' => 'other']), 'recent' => 500], [], $may)['status']);
    }

    public function test_capabilities(): void
    {
        $this->fake(['capabilities' => ['can_moderate']]);
        $this->assertTrue(Upload::skipsQueue(Supabase::forToken('t')));
        $this->assertFalse(Upload::mayReplaceAnyAsset(Supabase::forToken('t')));

        $this->fake(['capabilities' => ['can_publish_unreviewed']]);
        $this->assertTrue(Upload::skipsQueue(Supabase::forToken('t')));

        $this->fake(['capabilities' => ['can_seed_assets']]);
        $this->assertFalse(Upload::skipsQueue(Supabase::forToken('t')));
    }

    public function test_a_capability_that_could_not_be_read_is_no(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
        $this->assertFalse(Upload::skipsQueue(Supabase::forToken('t')));
        $this->assertFalse(Upload::mayReplaceAnyAsset(Supabase::forToken('t')));
    }

    public function test_a_new_row(): void
    {
        $this->fake();
        $id = Upload::write(Supabase::admin(), self::USER, $this->declaration(), self::HASH, 'units/BYAR/buildpic/encabc.webp', ['width' => 128, 'height' => 128], null, false);
        $this->assertSame('new-row', $id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/asset?') && $r->data() === [
            'game' => 'BYAR', 'unit_name' => 'armsolar', 'map_name' => null, 'faction_key' => null, 'variant' => 'buildpic',
            'source_hash' => 'raw-abc', 'hash' => 'encabc', 'encode_profile' => 'webp-lossless-256', 'path' => 'units/BYAR/buildpic/encabc.webp',
            'origin' => 'extracted', 'tier' => 'bucket', 'mime' => 'image/webp', 'bytes' => 4096, 'width' => 128, 'height' => 128,
            'map_width' => null, 'map_height' => null, 'world_height_min' => null, 'world_height_max' => null, 'source_archive' => 'byar_1.2.sdz',
            'moderation' => 'pending', 'approval_source' => null, 'uploaded_by' => self::USER,
        ] && str_contains($r->header('Prefer')[0], 'return=representation'));
    }

    public function test_a_faction_row_names_its_faction_and_no_unit_or_map(): void
    {
        $this->fake();
        Upload::write(Supabase::admin(), self::USER, $this->declaration(['identity' => AssetResolver::faction('BYAR', 'armada')]), self::HASH, 'p', ['width' => 64, 'height' => 64], null, true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['game'] === 'BYAR' && $r->data()['faction_key'] === 'armada' && $r->data()['unit_name'] === null && $r->data()['map_name'] === null
            && $r->data()['moderation'] === 'approved' && $r->data()['approval_source'] === 'bypass');
    }

    public function test_a_replacement_goes_back_to_the_queue_as_the_uploaders(): void
    {
        $this->fake();
        $id = Upload::write(Supabase::admin(), 'new-owner', $this->declaration(), self::HASH, 'p', ['width' => 128, 'height' => 128], 'held', false);
        $this->assertSame('held', $id);
        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->method() === 'PATCH' && str_contains($r->url(), 'id=eq.held')
                && $d['moderation'] === 'pending' && $d['approval_source'] === null && $d['tier'] === 'bucket'
                && $d['promoted_at'] === null && $d['bytes_missing_at'] === null && $d['uploaded_by'] === 'new-owner'
                && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $d['seen_at']);
        });
    }

    public function test_a_failed_write_is_null(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope', 'code' => '23514'], 400)]);
        $this->assertNull(Upload::write(Supabase::admin(), self::USER, $this->declaration(), self::HASH, 'p', ['width' => 1, 'height' => 1], null, false));
        $this->assertNull(Upload::write(Supabase::admin(), self::USER, $this->declaration(), self::HASH, 'p', ['width' => 1, 'height' => 1], 'held', false));
    }
}
