<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\Asset;
use Tests\TestCase;

/**
 * Ported from `lib/assets/asset.test.ts`. The migrations write the
 * vocabularies down as check constraints, and these prove the two copies
 * still agree. They read `supabase/migrations` in the repository root.
 */
class AssetTest extends TestCase
{
    /** @return list<string> */
    private function migrations(): array
    {
        $files = glob(base_path('../supabase/migrations/*.sql')) ?: [];
        sort($files);

        return array_map(fn ($f) => (string) file_get_contents($f), $files);
    }

    /** The last migration constraining `$column`, read for its literal list. @return list<string> */
    private function listTheDatabaseAccepts(string $column): array
    {
        $pattern = '/\b'.$column.' in \(([^)]*)\)/';
        $last = null;
        foreach ($this->migrations() as $sql) {
            if (preg_match($pattern, $sql, $m)) {
                $last = $m[1];
            }
        }
        preg_match_all("/'([^']+)'/", (string) $last, $values);
        $list = $values[1];
        sort($list);

        return $list;
    }

    private function sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    public function test_the_database_and_the_hub_agree_on_every_list(): void
    {
        $this->assertNotSame([], $this->migrations(), 'supabase/migrations not found');
        $this->assertSame($this->sorted(Asset::TIERS), $this->listTheDatabaseAccepts('tier'));
        $this->assertSame($this->sorted(Asset::MODERATION_STATES), $this->listTheDatabaseAccepts('moderation'));
        $this->assertSame($this->sorted(Asset::origins()), $this->listTheDatabaseAccepts('origin'));
        $this->assertSame($this->sorted(Asset::APPROVAL_SOURCES), $this->listTheDatabaseAccepts('approval_source'));
        $this->assertSame($this->sorted(Asset::REJECTION_KINDS), $this->listTheDatabaseAccepts('rejection_kind'));
        $this->assertSame($this->sorted(Asset::EVENT_ACTIONS), $this->listTheDatabaseAccepts('action'));
        $this->assertSame($this->sorted(Asset::mapVariants()), $this->listTheDatabaseAccepts('variant'));
    }

    public function test_a_moderator_may_only_choose_between_the_two_real_kinds(): void
    {
        $this->assertSame(['editorial', 'safety'], $this->sorted(Asset::MODERATOR_REJECTION_KINDS));
        $this->assertFalse(Asset::isModeratorRejectionKind('unrecorded'));
    }

    public function test_the_unit_variant_rule_is_spelled_the_same_way_in_both_places(): void
    {
        $last = null;
        foreach ($this->migrations() as $sql) {
            if (str_contains($sql, 'asset_unit_variant_check')) {
                $last = $sql;
            }
        }
        $this->assertNotNull($last);
        $this->assertStringContainsString("variant = '".Asset::unitBuildpicVariant()."'", $last);
        $this->assertStringContainsString("variant like '".Asset::unitRenderVariantPrefix()."%'", $last);
    }

    public function test_the_vocabulary_names_the_top_render(): void
    {
        $this->assertContains('top', Asset::unitRenderAngles());
        $this->assertSame('render:top', Asset::unitTopRenderVariant());
        $this->assertTrue(Asset::isMapVariant('overlay:height'));
        $this->assertFalse(Asset::isMapVariant('buildpic'));
    }
}
