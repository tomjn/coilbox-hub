<?php

namespace App\Domain\Gallery\Art;

/**
 * Ported from `components/art/palette.ts`. Every drawing tints from one theme
 * colour, so there is only one palette.
 */
class Palette
{
    public const WIDTH = 320;

    public const HEIGHT = 200;

    private const ACHROMATIC_SATURATION = 8;

    /** @var array{h: float, s: float, l: float} hsl(0 0% 98.04%), the Next app's `--foreground`. */
    public const THEME = ['h' => 0.0, 's' => 0.0, 'l' => 98.04];

    private static ?array $palette = null;

    /** @return array{glow: string, faint: string, line: string, spark: string} */
    public static function get(): array
    {
        return self::$palette ??= self::paletteFor(self::THEME);
    }

    public static function clamp(float|int $n, float|int $min, float|int $max): float|int
    {
        return min($max, max($min, $n));
    }

    /** JavaScript's `Math.round(n * 100) / 100`. Math.round rounds halves up. */
    public static function round(float|int $n): float
    {
        return floor($n * 100 + 0.5) / 100;
    }

    /** Formats a number the way JavaScript's `String(n)` does. */
    public static function num(float|int $n): string
    {
        $n = (float) $n;
        if ($n == 0.0) {
            return '0';
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }

        $sign = $n < 0 ? '-' : '';
        $digits = self::shortestDigits(abs($n), $exp);
        $k = strlen($digits);
        $point = $exp + 1;

        if ($k <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }
        $e = $point - 1;
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.($e < 0 ? '-' : '+').abs($e);
    }

    /**
     * Finds the shortest digit string that reads back as the same double, and
     * the decimal exponent of its first digit.
     */
    private static function shortestDigits(float $n, ?int &$exp): string
    {
        for ($precision = 1; $precision <= 17; $precision++) {
            $candidate = sprintf('%.'.($precision - 1).'e', $n);
            if ((float) $candidate === $n) {
                [$mantissa, $e] = explode('e', $candidate);
                $exp = (int) $e;

                return rtrim(str_replace('.', '', $mantissa), '0') ?: '0';
            }
        }
        [$mantissa, $e] = explode('e', sprintf('%.16e', $n));
        $exp = (int) $e;

        return rtrim(str_replace('.', '', $mantissa), '0') ?: '0';
    }

    public static function hsl(float|int $h, float|int $s, float|int $l): string
    {
        $hue = self::round(fmod(fmod($h, 360) + 360, 360));

        return 'hsl('.self::num($hue).' '.self::num(self::round(self::clamp($s, 0, 100))).'% '
            .self::num(self::round(self::clamp($l, 0, 100))).'%)';
    }

    /** A diamond as a polygon, matching Coilbox's `diamond`. */
    public static function diamond(float|int $x, float|int $y, float|int $r): string
    {
        $n = fn ($v) => self::num($v);

        return '<polygon points="'.$n($x).','.$n($y - $r).' '.$n($x + $r).','.$n($y).' '
            .$n($x).','.$n($y + $r).' '.$n($x - $r).','.$n($y).'"/>';
    }

    /** @return array{glow: string, faint: string, line: string, spark: string} */
    public static function paletteFor(array $theme): array
    {
        $neutral = $theme['s'] < self::ACHROMATIC_SATURATION;
        $sat = $neutral ? self::clamp($theme['s'], 0, 6) : self::clamp($theme['s'], 24, 58);
        $hue = fn ($offset) => $neutral ? $theme['h'] : $theme['h'] + $offset;

        return [
            'glow' => self::hsl($hue(22), min($sat + 14, 70), 58),
            'faint' => self::hsl($hue(0), min($sat + 6, 62), 54),
            'line' => self::hsl($hue(6), min($sat + 18, 72), 70),
            'spark' => self::hsl($hue(-16), $neutral ? $sat : min($sat + 30, 82), 80),
        ];
    }
}
