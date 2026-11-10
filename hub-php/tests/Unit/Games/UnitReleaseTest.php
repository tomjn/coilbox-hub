<?php

namespace Tests\Unit\Games;

use App\Domain\Games\UnitRelease;
use Tests\TestCase;

/** Ported from `lib/games/unitRelease.test.ts`. */
class UnitReleaseTest extends TestCase
{
    public function test_a_units_latest_facts_are_its_name_alone(): void
    {
        $this->assertSame('armcom', UnitRelease::unitSegment('armcom'));
        $this->assertSame(['unit' => 'armcom'], UnitRelease::readUnitSegment('armcom'));
    }

    public function test_a_release_reads_back_as_written_separators_in_either_half_included(): void
    {
        foreach ([['building2', 'test-323-1a8581e'], ['odd@unit', 'v1@beta'], ['a b/c', '1.0 %rc']] as [$unit, $release]) {
            $this->assertSame(
                ['unit' => $unit, 'release' => $release],
                UnitRelease::readUnitSegment(UnitRelease::unitSegment($unit, $release)),
            );
        }
    }

    public function test_only_the_separator_is_a_literal_at_sign(): void
    {
        $this->assertSame('odd%40unit@v1%40beta', UnitRelease::unitSegment('odd@unit', 'v1@beta'));
    }

    public function test_a_segment_that_is_not_valid_percent_encoding_is_read_as_it_stands(): void
    {
        $this->assertSame(['unit' => 'bad%zz', 'release' => '1.0'], UnitRelease::readUnitSegment('bad%zz@1.0'));
    }
}
