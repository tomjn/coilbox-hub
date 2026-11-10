<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Dates as people read them, from the Next app's `lib/text/dates.ts`: the
 * day in British order and in UTC, so every reader and the server agree.
 * The relative form ("3 days ago") is drawn in the browser, which knows the
 * time now.
 */
final class Dates
{
    /** `Intl` writes September as "Sept" in en-GB, and the rest as PHP does. */
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];

    /** "9 Aug 2026", or "9 Aug 2026, 16:49" with the time. Empty when the value is not a date. */
    public static function format(string $value, bool $time = false): string
    {
        try {
            $date = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return '';
        }
        $day = $date->format('j').' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');

        return $time ? $day.', '.$date->format('H:i') : $day;
    }
}
