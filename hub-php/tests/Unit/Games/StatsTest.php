<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Stats;
use Tests\TestCase;

/**
 * Ported from `lib/games/stats.test.ts`. The blob is schemaless, so what is under
 * test is the honesty rules: known keys lead, unknown keys print as themselves,
 * and absent stays absent.
 */
class StatsTest extends TestCase
{
    private const WEAPONS = [
        ['range' => 300, 'damage' => 75, 'reload' => 0.4, 'projectile' => 'BeamLaser'],
        ['range' => 250, 'damage' => 99999, 'projectile' => 'DGun'],
    ];

    public function test_known_keys_lead_in_reading_order_unknown_keys_follow_alphabetically(): void
    {
        $rows = Stats::statRows(['zeta' => 1, 'energyCost' => 800, 'health' => 5000, 'metalCost' => 200, 'alpha' => 2]);

        $this->assertSame(['health', 'metalCost', 'energyCost', 'alpha', 'zeta'], array_column($rows, 'key'));
    }

    public function test_an_unknown_key_prints_as_itself(): void
    {
        $this->assertSame('shieldCapacity', Stats::statLabel('shieldCapacity'));
        $this->assertSame('Health', Stats::statLabel('health'));
    }

    public function test_absent_stays_absent_and_structures_arrive_as_data(): void
    {
        $this->assertSame('-', Stats::formatStatValue(null));
        $this->assertSame('4500', Stats::formatStatValue(4500));
        $this->assertSame('true', Stats::formatStatValue(true));
        $this->assertSame('yes', Stats::formatStatValue('yes'));
        $this->assertSame('{"damage":40,"reload":1.5}', Stats::formatStatValue(['damage' => 40, 'reload' => 1.5]));
    }

    public function test_numbers_print_the_way_javascript_writes_them(): void
    {
        $this->assertSame('890', Stats::formatStatValue(890.0));
        $this->assertSame('[{"range":300}]', Stats::formatStatValue([['range' => 300.0]]));
    }

    public function test_an_array_of_flat_records_is_tabular(): void
    {
        $this->assertSame(self::WEAPONS, Stats::tabularStatRows(self::WEAPONS));
    }

    public function test_anything_else_keeps_its_compact_json(): void
    {
        $this->assertNull(Stats::tabularStatRows(300));
        $this->assertNull(Stats::tabularStatRows(['BeamLaser', 'DGun']));
        $this->assertNull(Stats::tabularStatRows([]));
        $this->assertNull(Stats::tabularStatRows([['ok' => true], 'not a record']));
    }

    public function test_columns_come_back_in_first_appearance_order_and_every_column_survives(): void
    {
        // DGun carries no reload, and the column stays because the BeamLaser does.
        $this->assertSame(['range', 'damage', 'reload', 'projectile'], Stats::tabularColumns(self::WEAPONS));
    }

    public function test_compare_rows_are_the_union_of_both_sides_known_keys_first_then_alphabetical(): void
    {
        $rows = Stats::compareStatRows(
            ['zeta' => 1, 'health' => 10, 'alpha' => 2],
            ['energyCost' => 5, 'health' => 10, 'beta' => 3],
        );

        $this->assertSame(['health', 'energyCost', 'alpha', 'beta', 'zeta'], array_column($rows, 'key'));
        $this->assertSame('Energy cost', $rows[1]['label']);
    }

    public function test_compare_rows_mark_a_differing_value_and_leave_an_equal_one(): void
    {
        $rows = Stats::compareStatRows(['health' => 10, 'metalCost' => 50], ['health' => 12, 'metalCost' => 50]);

        $this->assertSame([['health', true], ['metalCost', false]], array_map(fn ($row) => [$row['key'], $row['changed']], $rows));
        $this->assertSame('10', $rows[0]['left']);
        $this->assertSame('12', $rows[0]['right']);
    }

    public function test_a_stat_on_one_side_only_reads_as_a_dash_on_the_other_and_counts_as_different(): void
    {
        $rows = Stats::compareStatRows(['health' => 10], ['health' => 10, 'buildSpeed' => 80]);

        $this->assertSame(['key' => 'buildSpeed', 'label' => 'buildSpeed', 'left' => '-', 'right' => '80', 'changed' => true], $rows[1]);
    }

    public function test_array_and_object_values_compare_by_what_they_print(): void
    {
        $same = Stats::compareStatRows(['weapons' => [['range' => 1]]], ['weapons' => [['range' => 1]]]);
        $this->assertFalse($same[0]['changed']);

        $different = Stats::compareStatRows(['weapons' => [['range' => 1]]], ['weapons' => [['range' => 2]]]);
        $this->assertTrue($different[0]['changed']);
        $this->assertSame('[{"range":2}]', $different[0]['right']);
    }

    public function test_a_number_against_the_same_digits_as_a_string_is_not_marked_different(): void
    {
        $this->assertFalse(Stats::compareStatRows(['health' => 10], ['health' => '10'])[0]['changed']);
        $this->assertTrue(Stats::compareStatRows(['health' => 10], ['health' => 'ten'])[0]['changed']);
    }

    public function test_two_empty_sides_give_no_rows(): void
    {
        $this->assertSame([], Stats::compareStatRows([], []));
    }
}
