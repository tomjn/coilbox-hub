<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Container;
use App\Domain\Gallery\Labels;
use PHPUnit\Framework\TestCase;

/** Ported from the Next app's `lib/gallery/label.test.ts`. */
class LabelsTest extends TestCase
{
    public function test_the_kinds_read_as_a_sentence_in_the_plural(): void
    {
        $this->assertSame(
            'Presets, challenges, setup packs, scenarios, blueprints, projects and modules',
            Labels::kindsPlural(),
        );
    }

    public function test_the_kinds_read_as_a_sentence_in_the_singular_for_sharing_one_thing(): void
    {
        $this->assertSame(
            'preset, challenge, setup pack, scenario, blueprint, project or module',
            Labels::kindsSingular(),
        );
    }

    public function test_neither_sentence_can_fall_behind_the_kinds_the_gallery_carries(): void
    {
        foreach (Container::GALLERY_KINDS as $kind) {
            $this->assertStringContainsString(
                mb_strtolower(Labels::kindPlural($kind)),
                mb_strtolower(Labels::kindsPlural()),
            );
        }
    }
}
