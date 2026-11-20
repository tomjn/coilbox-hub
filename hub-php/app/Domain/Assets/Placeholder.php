<?php

namespace App\Domain\Assets;

/**
 * The last rung of the serving ladder, what to draw when the hub holds no
 * picture, `lib/assets/placeholder.ts` in the Next app.
 *
 * It answers with a description rather than a URL, and
 * `<x-assets.placeholder>` draws it as markup in the page, so a missing picture
 * costs no request.
 *
 * A footprint is `['width' => n, 'height' => n]`. For a unit it is build
 * squares. For a map it is 512 elmo squares, so a 6144 elmo map is 12.
 */
final class Placeholder
{
    /** The longer side of the drawing box, in user units. */
    private const LONGEST = 100;

    /** The most out of square a placeholder is drawn. Nothing real reaches it. */
    private const MOST_ELONGATED = 8;

    /**
     * Everything needed to draw a picture that does not exist. `$pending`
     * when one has been uploaded and is waiting for a moderator.
     *
     * @param  array<string, string>  $identity
     * @param  array{width: int|float, height: int|float}|null  $footprint
     * @return array{name: string, keyedOn: string, footprint: array{width: int|float, height: int|float}|null, pending: bool}
     */
    public static function missingPicture(array $identity, ?array $footprint, bool $pending = false): array
    {
        $name = match ($identity['keyedOn']) {
            'unit' => $identity['unitName'],
            'faction' => $identity['factionKey'],
            'map' => $identity['mapName'],
        };

        return ['name' => $name, 'keyedOn' => $identity['keyedOn'], 'footprint' => $footprint, 'pending' => $pending];
    }

    /**
     * The box to draw the footprint in, with the longer side 100. A square when
     * there is no usable footprint.
     *
     * @param  array{width: int|float, height: int|float}|null  $footprint
     * @return array{width: int|float, height: int|float}
     */
    public static function box(?array $footprint): array
    {
        $square = ['width' => self::LONGEST, 'height' => self::LONGEST];
        if ($footprint === null) {
            return $square;
        }
        $width = self::usable($footprint['width']);
        $height = self::usable($footprint['height']);
        if ($width === null || $height === null) {
            return $square;
        }

        $ratio = min(max($width / $height, 1 / self::MOST_ELONGATED), self::MOST_ELONGATED);

        return $ratio >= 1
            ? ['width' => self::LONGEST, 'height' => self::LONGEST / $ratio]
            : ['width' => self::LONGEST * $ratio, 'height' => self::LONGEST];
    }

    /**
     * The footprint as a caption, or null. A unit names its units because build
     * squares mean nothing unsaid. A map does not, because "12 by 12" is how
     * players name a map size.
     *
     * @param  array{name: string, keyedOn: string, footprint: array{width: int|float, height: int|float}|null}  $picture
     */
    public static function measure(array $picture): ?string
    {
        $footprint = $picture['footprint'];
        if ($footprint === null) {
            return null;
        }
        $width = self::usable($footprint['width']);
        $height = self::usable($footprint['height']);
        if ($width === null || $height === null) {
            return null;
        }

        $size = self::number($width).' by '.self::number($height);

        return $picture['keyedOn'] === 'unit' ? $size.' build squares' : $size;
    }

    /**
     * What the drawing says to somebody who cannot see it: that there is no
     * picture, then what is known instead.
     *
     * @param  array{name: string, keyedOn: string, footprint: array{width: int|float, height: int|float}|null}  $picture
     */
    public static function label(array $picture): string
    {
        $measure = self::measure($picture);
        $none = self::pending($picture)
            ? 'The picture of '.$picture['name'].' is awaiting approval'
            : 'No picture of '.$picture['name'].' yet';
        if ($measure === null) {
            return $none;
        }

        return $picture['keyedOn'] === 'unit'
            ? $none.', which stands on '.$measure
            : $none.', a '.$measure.' map';
    }

    /**
     * The caption under the drawing.
     *
     * @param  array{name: string, keyedOn: string, footprint: array{width: int|float, height: int|float}|null, pending?: bool}  $picture
     */
    public static function caption(array $picture): string
    {
        $measure = self::measure($picture);
        $state = self::pending($picture) ? 'awaiting approval' : 'no picture yet';

        return $measure !== null ? $measure.', '.$state : ucfirst($state);
    }

    /**
     * Callers that build the array by hand may leave `pending` out.
     *
     * @param  array<string, mixed>  $picture
     */
    public static function pending(array $picture): bool
    {
        return ($picture['pending'] ?? false) === true;
    }

    /** A number as JavaScript prints it, `50` or `33.333333333333336`, for the SVG attributes. */
    public static function jsNumber(int|float $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** Footprints are whole numbers, so a stray fraction is rounded to one place. */
    private static function number(int|float $value): string
    {
        return self::jsNumber(round($value * 10) / 10);
    }

    private static function usable(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && $value > 0 ? $value : null;
    }
}
