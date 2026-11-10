<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Have;
use Tests\TestCase;

/** Ported from `lib/assets/have.test.ts`. */
class HaveTest extends TestCase
{
    public function test_two_shapes_never_produce_the_same_key(): void
    {
        $this->assertNotSame(
            Have::identityKey(AssetResolver::unit('bar', 'armsolar')),
            Have::identityKey(['keyedOn' => 'map', 'mapName' => 'armsolar', 'variant' => 'buildpic']),
        );
        $this->assertNotSame(
            Have::identityKey(AssetResolver::unit('bar', 'arm solar')),
            Have::identityKey(AssetResolver::unit('bar arm', 'solar')),
        );
        $this->assertNotSame(
            Have::identityKey(AssetResolver::faction('bar', 'armada')),
            Have::identityKey(AssetResolver::unit('bar', 'armada', 'logo')),
        );
    }

    public function test_the_filters(): void
    {
        $this->assertSame('and(game.eq."bar",unit_name.eq."armsolar",variant.eq."buildpic")', Have::identityFilter(AssetResolver::unit('bar', 'armsolar')));
        $this->assertSame('and(map_name.eq."Comet Catcher Remake 1.8",variant.eq."minimap")', Have::identityFilter(AssetResolver::map('Comet Catcher Remake 1.8')));
        $this->assertSame('and(game.eq."bar",faction_key.eq."armada",variant.eq."logo")', Have::identityFilter(AssetResolver::faction('bar', 'armada')));
    }

    public function test_a_map_name_full_of_punctuation_stays_inside_one_operand(): void
    {
        $this->assertSame(
            'and(map_name.eq."a,b.c(d):e\\"f\\\\g",variant.eq."minimap")',
            Have::identityFilter(AssetResolver::map('a,b.c(d):e"f\\g')),
        );
    }

    public function test_batches_are_split(): void
    {
        $identities = range(0, 499);
        $chunks = Have::queryChunks($identities);
        $this->assertCount(10, $chunks);
        $this->assertCount(50, $chunks[0]);
        $this->assertSame($identities, array_merge(...$chunks));
        $this->assertSame([[1, 2], [3, 4], [5]], Have::queryChunks([1, 2, 3, 4, 5], 2));
    }

    public function test_one_query_stays_under_the_request_line(): void
    {
        $filters = [];
        for ($i = 0; $i < 50; $i++) {
            $filters[] = Have::identityFilter(AssetResolver::map('A Long Enough Map Name To Be Realistic '.$i));
        }
        $this->assertLessThan(8000, strlen(rawurlencode(implode(',', $filters))));
    }

    public function test_held_for_have(): void
    {
        $lost = '2026-09-14T12:00:00Z';
        $this->assertTrue(Have::heldForHave(['moderation' => 'approved', 'bytes_missing_at' => null]));
        $this->assertFalse(Have::heldForHave(['moderation' => 'approved', 'bytes_missing_at' => $lost]));
        $this->assertFalse(Have::heldForHave(['moderation' => 'pending', 'bytes_missing_at' => $lost]));
        $this->assertTrue(Have::heldForHave(['moderation' => 'rejected', 'bytes_missing_at' => $lost]));
    }

    public function test_row_identity(): void
    {
        $this->assertSame(AssetResolver::faction('bar', 'armada'), Have::rowIdentity(['game' => 'bar', 'unit_name' => null, 'map_name' => null, 'faction_key' => 'armada', 'variant' => 'logo']));
        $this->assertSame(AssetResolver::unit('bar', 'armsolar'), Have::rowIdentity(['game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic']));
        $this->assertSame(AssetResolver::map('Comet Catcher Remake 1.8'), Have::rowIdentity(['game' => null, 'unit_name' => null, 'map_name' => 'Comet Catcher Remake 1.8', 'variant' => 'minimap']));
    }
}
