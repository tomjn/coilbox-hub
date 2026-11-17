<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Catalogue;
use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\Signing;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use MirrorFixtures;

    /** The lists of a fixture catalogue, shaped as `catalogue_source` returns them. */
    private static function sourceFrom(string $fixture, int $seq): array
    {
        $json = json_decode(self::fixture($fixture), true, flags: JSON_THROW_ON_ERROR);

        return ['seq' => $seq, 'mirrors' => $json['mirrors'], 'archives' => $json['archives'], 'removed' => $json['removed']];
    }

    private static function sigOf(string $body, string $secretKeyB64): string
    {
        return B64::encode(Signing::sign(Signing::CTX_CATALOGUE, $body, B64::decode($secretKeyB64)));
    }

    public function test_encode_matches_the_fixture_catalogue_byte_for_byte(): void
    {
        $body = Catalogue::encode(self::sourceFrom('catalogue.json', 1), 1794787200, null);

        $this->assertSame(self::fixture('catalogue.json'), $body);
        $this->assertSame(self::fixture('catalogue.sig'), self::sigOf($body, self::fixtureKeys()['hub_secret_key']));
    }

    public function test_encode_with_next_key_matches_the_rotated_fixture(): void
    {
        $keys = self::fixtureKeys();
        config([
            'hub.mirrors.secret_key' => $keys['hub_secret_key'],
            'hub.mirrors.next_secret_key' => $keys['rotated_hub_secret_key'],
        ]);
        $nextKey = HubKeys::fromConfig()->nextKey();

        $body = Catalogue::encode(self::sourceFrom('catalogue-rotated.json', 2), 1794790800, $nextKey);

        $this->assertSame(self::fixture('catalogue-rotated.json'), $body);
        $this->assertSame(self::fixture('catalogue-rotated.sig'), self::sigOf($body, $keys['rotated_hub_secret_key']));
    }

    public function test_empty_lists_encode_as_arrays(): void
    {
        $body = Catalogue::encode(['seq' => 3, 'mirrors' => [], 'archives' => [], 'removed' => []], 10, null);

        $this->assertSame('{"v":1,"seq":3,"generated_at":10,"mirrors":[],"archives":[],"removed":[]}', $body);
    }
}
