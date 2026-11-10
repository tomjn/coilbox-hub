<?php

namespace Tests\Unit\Gallery;

use App\Support\Dates;
use PHPUnit\Framework\TestCase;

/** Ported from `formatDate` in the Next app's `lib/text/dates.test.ts`. The relative form runs in the browser. */
class DatesTest extends TestCase
{
    public function test_format_writes_day_short_month_and_year_in_utc(): void
    {
        $this->assertSame('9 Aug 2026', Dates::format('2026-08-09T12:00:00.000Z'));
    }

    public function test_format_uses_utc_not_the_machines_zone_near_midnight(): void
    {
        $this->assertSame('9 Aug 2026', Dates::format('2026-08-09T23:59:59.000Z'));
        $this->assertSame('10 Aug 2026', Dates::format('2026-08-10T00:00:00.000Z'));
    }

    public function test_format_converts_an_offset_to_utc(): void
    {
        $this->assertSame('9 Aug 2026', Dates::format('2026-08-10T01:00:00+02:00'));
    }

    public function test_format_can_add_the_time_of_day(): void
    {
        $this->assertSame('9 Aug 2026, 14:05', Dates::format('2026-08-09T14:05:00.000Z', true));
        $this->assertSame('9 Aug 2026, 00:07', Dates::format('2026-08-09T00:07:00.000Z', true));
    }

    public function test_format_gives_an_empty_string_for_an_invalid_date(): void
    {
        $this->assertSame('', Dates::format('not a date'));
    }

    public function test_september_is_written_sept(): void
    {
        $this->assertSame('3 Sept 2026', Dates::format('2026-09-03T10:00:00.000Z'));
    }

    public function test_every_other_month_is_its_three_letter_name(): void
    {
        $expected = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
        foreach ($expected as $i => $name) {
            $this->assertSame('1 '.$name.' 2026', Dates::format(sprintf('2026-%02d-01T12:00:00.000Z', $i + 1)));
        }
    }
}
