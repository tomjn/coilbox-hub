<?php

namespace App\Domain\Games;

/**
 * A unit at a past release is addressed as `<unit>@<release>` in the unit's own
 * path segment, as `lib/games/unitRelease.ts`, so the page has one param.
 *
 * Both halves are percent-encoded, which turns any `@` in them into `%40`, so
 * the only literal `@` is the separator.
 */
final class UnitRelease
{
    /** The path segment for `$unit` at `$release`, or for its latest facts. */
    public static function unitSegment(string $unit, ?string $release = null): string
    {
        $name = Js::encodeURIComponent($unit);

        return $release !== null && $release !== '' ? $name.'@'.Js::encodeURIComponent($release) : $name;
    }

    /**
     * The unit and release a segment names. The `release` key is present only
     * when the segment has an `@`.
     *
     * @return array{unit: string, release?: string}
     */
    public static function readUnitSegment(string $segment): array
    {
        $at = strpos($segment, '@');
        if ($at === false) {
            return ['unit' => self::decode($segment)];
        }

        return ['unit' => self::decode(substr($segment, 0, $at)), 'release' => self::decode(substr($segment, $at + 1))];
    }

    /** A segment that is not valid percent-encoding is read as it stands, which finds no unit. */
    private static function decode(string $part): string
    {
        return Js::decodeURIComponent($part) ?? $part;
    }
}
