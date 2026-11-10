<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\Listings;
use PHPUnit\Framework\TestCase;

/** Ported from the Next app's `lib/gallery/cached.test.ts`. */
class ListingsTest extends TestCase
{
    public function test_a_facet_offers_each_value_once_sorted(): void
    {
        $this->assertSame(['arm', 'bar'], Listings::distinct(['bar', 'arm', 'bar']));
    }

    public function test_a_row_with_nothing_in_the_column_offers_no_chip(): void
    {
        $this->assertSame(['bar'], Listings::distinct(['bar', null, null]));
        $this->assertSame([], Listings::distinct(null));
    }

    public function test_a_facet_stays_a_row_of_chips_rather_than_a_list(): void
    {
        $many = [];
        for ($i = 0; $i < 40; $i++) {
            $many[] = sprintf('game-%02d', $i);
        }
        $this->assertCount(20, Listings::distinct($many));
    }

    public function test_the_chips_are_split_by_facet_sorted_with_empty_values_left_out(): void
    {
        $this->assertSame(
            ['games' => ['bar', 'tabtest'], 'maps' => ['Seed Map']],
            Listings::facetsFromRows([
                ['facet' => 'map', 'value' => 'Seed Map'],
                ['facet' => 'game', 'value' => 'tabtest'],
                ['facet' => 'game', 'value' => 'bar'],
                ['facet' => 'game', 'value' => null],
                ['facet' => 'map', 'value' => ''],
            ]),
        );
        $this->assertSame(['games' => [], 'maps' => []], Listings::facetsFromRows(null));
    }
}
