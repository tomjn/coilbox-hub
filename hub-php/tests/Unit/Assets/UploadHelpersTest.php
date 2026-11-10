<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetPath;
use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\AuthDiscovery;
use App\Domain\Assets\SourceConflict;
use App\Domain\Assets\UploadIp;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/assets/uploadIp.test.ts`, `hash.test.ts`,
 * `sourceConflict.test.ts`, `vocabularyDigest.test.ts` and
 * `lib/maps/catalogDigest.test.ts`.
 */
class UploadHelpersTest extends TestCase
{
    private function request(array $headers): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'POST', server: $server);
    }

    public function test_client_ip(): void
    {
        // The socket address, whatever the uploader puts in the headers.
        $spoofed = $this->request(['x-real-ip' => '198.51.100.1', 'x-forwarded-for' => '198.51.100.2']);
        $spoofed->server->set('REMOTE_ADDR', '203.0.113.7');
        $this->assertSame('203.0.113.7', UploadIp::clientIp($spoofed));

        $unknown = $this->request(['x-real-ip' => '203.0.113.7']);
        $unknown->server->set('REMOTE_ADDR', '');
        $this->assertNull(UploadIp::clientIp($unknown));
    }

    public function test_what_counts_as_an_address(): void
    {
        $this->assertTrue(UploadIp::isIpAddress('203.0.113.7'));
        $this->assertTrue(UploadIp::isIpAddress('2001:db8::1'));
        $this->assertTrue(UploadIp::isIpAddress('::1'));
        $this->assertFalse(UploadIp::isIpAddress('203.0.113.999'));
        $this->assertFalse(UploadIp::isIpAddress('203.0.113'));
        $this->assertFalse(UploadIp::isIpAddress(''));
        $this->assertFalse(UploadIp::isIpAddress("'; delete from asset_upload_ip"));
        $this->assertFalse(UploadIp::isIpAddress("203.0.113.7\n"));
    }

    /** The upload route hashes with `hash('sha256', ...)`. Pinned against the published vectors. */
    public function test_the_hash_is_sha256_lowercase_hex_and_a_path_segment(): void
    {
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', hash('sha256', ''));
        $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', hash('sha256', 'abc'));
        $hash = hash('sha256', 'a minimap');
        $this->assertSame('maps/minimap/'.$hash.'.webp', AssetPath::objectPath(AssetResolver::map('Tangerine 1.1'), $hash, 'image/webp'));
    }

    private function held(array $over = []): array
    {
        return [...['id' => 'held', 'source_hash' => 'raw-old', 'source_archive' => 'byar_1.1.sdz', 'moderation' => 'approved'], ...$over];
    }

    private function declared(): array
    {
        return ['sourceHash' => 'raw-new', 'sourceArchive' => 'byar_1.2.sdz'];
    }

    public function test_source_conflicts(): void
    {
        $this->assertNull(SourceConflict::detect($this->held(), $this->declared(), 'u'));
        $this->assertNull(SourceConflict::detect($this->held(['source_archive' => 'byar_1.2.sdz', 'source_hash' => 'raw-new']), $this->declared(), 'u'));
        $this->assertSame([
            'assetId' => 'held', 'sourceArchive' => 'byar_1.2.sdz', 'heldSourceHash' => 'raw-old', 'reportedSourceHash' => 'raw-new', 'reportedBy' => 'stranger',
        ], SourceConflict::detect($this->held(['source_archive' => 'byar_1.2.sdz']), $this->declared(), 'stranger'));
        $this->assertNotNull(SourceConflict::detect($this->held(['source_archive' => 'byar_1.2.sdz', 'moderation' => 'pending']), $this->declared(), 'u'));
        $this->assertNull(SourceConflict::detect($this->held(['source_archive' => 'byar_1.2.sdz', 'moderation' => 'rejected']), $this->declared(), 'u'));
    }

    public function test_a_disagreement_is_written_once_per_set_of_reported_bytes(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.service_role_key' => 'sb_secret_test']);
        Http::fake(['*' => Http::response('', 201)]);

        $conflict = SourceConflict::detect($this->held(['source_archive' => 'byar_1.2.sdz']), $this->declared(), 'stranger');
        $this->assertTrue(SourceConflict::record(Supabase::admin(), $conflict));

        Http::assertSent(function (ClientRequest $r) {
            return $r->method() === 'POST'
                && str_contains($r->url(), '/rest/v1/asset_source_conflict?on_conflict=asset_id%2Creported_source_hash')
                && str_contains($r->header('Prefer')[0], 'resolution=ignore-duplicates')
                && $r->data() === ['asset_id' => 'held', 'source_archive' => 'byar_1.2.sdz', 'held_source_hash' => 'raw-old', 'reported_source_hash' => 'raw-new', 'reported_by' => 'stranger'];
        });
    }

    /** The served digests must equal what the Next app recorded for the same vendored bytes. */
    public function test_the_digests_are_the_sha256_of_the_vendored_bytes(): void
    {
        $assets = json_decode((string) file_get_contents(base_path('../lib/assets/vendor/source.json')), true);
        $maps = json_decode((string) file_get_contents(base_path('../lib/maps/vendor/source.json')), true);

        $this->assertSame('sha256:'.$assets['sha256']['asset-vocabulary.json'], AuthDiscovery::digest('asset-vocabulary.json'));
        $this->assertSame('sha256:'.$maps['sha256']['map-catalog.json'], AuthDiscovery::digest('map-catalog.json'));
        $this->assertNotSame(AuthDiscovery::digest('asset-vocabulary.json'), AuthDiscovery::digest('map-catalog.json'));
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', AuthDiscovery::digest('map-catalog.json'));
    }
}
