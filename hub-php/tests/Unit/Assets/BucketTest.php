<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\Bucket;
use App\Domain\Assets\Cdn;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/assets/bucket.test.ts`. */
class BucketTest extends TestCase
{
    private const PATH = 'units/bar/buildpic/abc.webp';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'sb_secret_test',
            'hub.asset_cdn_base' => null,
            'app.url' => 'https://hub.example',
        ]);
    }

    public function test_a_bucket_path_becomes_the_hubs_own_route(): void
    {
        $this->assertSame('https://hub.example/assets/staged/units/bar/buildpic/abc.webp', Bucket::tierUrl(self::PATH));
        $this->assertSame('https://hub.example/assets/staged/units/bar/buildpic/abc.webp', Bucket::tierUrl('/'.self::PATH));
    }

    /** @param list<array<string, string>> $rows */
    private function fake(array $rows, mixed $download = 'hello', int $rowStatus = 200): void
    {
        Http::fake([
            'sb.test/rest/v1/asset*' => Http::response($rows, $rowStatus),
            'sb.test/storage/v1/object/staged-pictures/*' => is_int($download)
                ? Http::response(['message' => 'x'], $download)
                : Http::response($download, 200, ['Content-Type' => 'image/webp']),
        ]);
    }

    private function fetch(): ?array
    {
        return Bucket::fetchApprovedStagedPicture(Supabase::anon(), Supabase::admin(), self::PATH);
    }

    public function test_an_approved_bucket_rows_bytes_are_read_from_the_bucket_at_its_path(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'bucket']]);

        $this->assertSame(['bytes' => 'hello', 'mime' => 'image/webp'], $this->fetch());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://sb.test/storage/v1/object/staged-pictures/units/bar/buildpic/abc.webp');
    }

    public function test_the_row_read_asks_for_this_path_approved_and_nothing_looser(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'bucket']]);
        $this->fetch();

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/rest/v1/asset')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['path'] === 'eq.'.self::PATH
                && $q['tier'] === 'in.(bucket,static)'
                && $q['moderation'] === 'eq.approved'
                && $q['order'] === 'tier.desc'
                && $q['limit'] === '1'
                && ! str_contains($r->header('apikey')[0], 'secret');
        });
    }

    public function test_a_promoted_row_answers_with_its_durable_url_and_the_bucket_is_never_asked(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'static']]);

        $this->assertSame(['promoted' => Cdn::staticTierUrl(self::PATH)], $this->fetch());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/storage/'));
    }

    public function test_no_approved_row_is_null_and_the_bucket_is_never_asked(): void
    {
        $this->fake([]);

        $this->assertNull($this->fetch());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/storage/'));
    }

    /** Storage answers a missing object with 400 and a `NoSuchKey` body. */
    public function test_an_approved_row_with_no_object_is_null(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'bucket']], 400);
        $this->assertNull($this->fetch());
    }

    public function test_an_approved_row_with_a_404_object_is_null(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'bucket']], 404);
        $this->assertNull($this->fetch());
    }

    public function test_any_other_bucket_failure_throws(): void
    {
        $this->fake([['mime' => 'image/webp', 'tier' => 'bucket']], 500);
        $this->expectException(SupabaseException::class);
        $this->fetch();
    }

    public function test_a_failed_row_read_throws(): void
    {
        $this->fake(['message' => 'connection refused'], 'x', 500);
        $this->expectException(SupabaseException::class);
        $this->fetch();
    }
}
