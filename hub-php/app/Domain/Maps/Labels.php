<?php

namespace App\Domain\Maps;

/**
 * What a map's row says, in the words a page prints, `lib/maps/labels.ts`.
 * The listing and the map's own page print the same sentences from here, so a
 * map is not described one way in a list and another one click later.
 */
final class Labels
{
    /** The engine's map square, in elmos. Every size a player says is a count of these. */
    private const ELMOS_PER_SQUARE = 512;

    /** Squares to one decimal place. Real maps are whole squares. */
    public static function squares(int|float $elmos): float
    {
        return floor(($elmos / self::ELMOS_PER_SQUARE) * 10 + 0.5) / 10;
    }

    /** The size as `12 x 20`, with no unit, the way players say it. */
    public static function size(int|float $widthElmos, int|float $heightElmos): string
    {
        return Js::number(self::squares($widthElmos)).' x '.Js::number(self::squares($heightElmos));
    }

    /** The size as a placeholder footprint, in squares. @return array{width: float, height: float} */
    public static function footprint(int|float $widthElmos, int|float $heightElmos): array
    {
        return ['width' => self::squares($widthElmos), 'height' => self::squares($heightElmos)];
    }

    /** Null rather than "0 players": no start positions is an incomplete extraction. */
    public static function players(int $startPositions): ?string
    {
        if ($startPositions < 1) {
            return null;
        }

        return $startPositions === 1 ? '1 player' : $startPositions.' players';
    }

    /** Both ends or neither. Half a range is a map that said nothing. */
    public static function wind(int|float|null $minWind, int|float|null $maxWind): ?string
    {
        if ($minWind === null || $maxWind === null) {
            return null;
        }

        return $minWind == $maxWind ? Js::number($minWind) : Js::number($minWind).' to '.Js::number($maxWind);
    }

    /** The archive's display name where it has one, the canonical name otherwise. */
    public static function title(?string $displayName, string $mapName): string
    {
        $trimmed = $displayName === null ? '' : Js::trim($displayName);

        return $trimmed !== '' ? $trimmed : $mapName;
    }
}
