<?php

namespace App\Domain\Og;

use InvalidArgumentException;

/**
 * An SVG path as a list of points GD can draw between. Only the absolute
 * `M`, `L` and `A` commands, which is all the coil uses.
 */
final class Path
{
    /** @return list<array{0: float, 1: float}> */
    public static function flatten(string $d): array
    {
        preg_match_all('/[MLA]|-?(?:\d+\.?\d*|\.\d+)/', $d, $matches);
        $tokens = $matches[0];
        $points = [];
        $command = null;
        $x = 0.0;
        $y = 0.0;
        $i = 0;
        $take = function () use (&$tokens, &$i): float {
            if (! isset($tokens[$i]) || ! is_numeric($tokens[$i])) {
                throw new InvalidArgumentException('Path ends early');
            }

            return (float) $tokens[$i++];
        };

        while ($i < count($tokens)) {
            if (! is_numeric($tokens[$i])) {
                $command = $tokens[$i++];
            }
            if ($command === 'M' || $command === 'L') {
                $x = $take();
                $y = $take();
                $points[] = [$x, $y];
                // Pairs after a move are lines.
                $command = 'L';
            } elseif ($command === 'A') {
                $rx = $take();
                $ry = $take();
                $rotation = $take();
                $large = $take() !== 0.0;
                $sweep = $take() !== 0.0;
                $tx = $take();
                $ty = $take();
                array_push($points, ...self::arc($x, $y, $rx, $ry, $rotation, $large, $sweep, $tx, $ty));
                $x = $tx;
                $y = $ty;
            } else {
                throw new InvalidArgumentException('Unsupported path command');
            }
        }

        return $points;
    }

    /**
     * An elliptical arc as points, by the endpoint to centre conversion in the
     * SVG specification, appendix F.6.5.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function arc(float $x1, float $y1, float $rx, float $ry, float $degrees, bool $large, bool $sweep, float $x2, float $y2): array
    {
        if ($rx == 0 || $ry == 0) {
            return [[$x2, $y2]];
        }
        $phi = deg2rad($degrees);
        $cos = cos($phi);
        $sin = sin($phi);
        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $x1p = $cos * $dx + $sin * $dy;
        $y1p = -$sin * $dx + $cos * $dy;
        $rx = abs($rx);
        $ry = abs($ry);
        $lambda = ($x1p ** 2) / ($rx ** 2) + ($y1p ** 2) / ($ry ** 2);
        if ($lambda > 1) {
            $rx *= sqrt($lambda);
            $ry *= sqrt($lambda);
        }
        $num = $rx ** 2 * $ry ** 2 - $rx ** 2 * $y1p ** 2 - $ry ** 2 * $x1p ** 2;
        $den = $rx ** 2 * $y1p ** 2 + $ry ** 2 * $x1p ** 2;
        $factor = sqrt(max(0, $num / $den)) * ($large === $sweep ? -1 : 1);
        $cxp = $factor * $rx * $y1p / $ry;
        $cyp = -$factor * $ry * $x1p / $rx;
        $cx = $cos * $cxp - $sin * $cyp + ($x1 + $x2) / 2;
        $cy = $sin * $cxp + $cos * $cyp + ($y1 + $y2) / 2;

        $start = atan2(($y1p - $cyp) / $ry, ($x1p - $cxp) / $rx);
        $end = atan2((-$y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx);
        $delta = $end - $start;
        if ($sweep && $delta < 0) {
            $delta += 2 * M_PI;
        } elseif (! $sweep && $delta > 0) {
            $delta -= 2 * M_PI;
        }

        $segments = max(2, (int) ceil(abs($delta) / (M_PI / 16)));
        $points = [];
        for ($s = 1; $s <= $segments; $s++) {
            $angle = $start + $delta * $s / $segments;
            $ex = $rx * cos($angle);
            $ey = $ry * sin($angle);
            $points[] = [$cos * $ex - $sin * $ey + $cx, $sin * $ex + $cos * $ey + $cy];
        }

        return $points;
    }
}
