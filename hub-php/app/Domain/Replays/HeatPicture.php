<?php

namespace App\Domain\Replays;

use App\Support\HubCache;
use Illuminate\Http\Response;

/**
 * Order heat drawn as Coilbox draws it: each cell's count spread into a soft
 * round blob (`buildHeatFieldFromCounts` in `src/lib/heatField.ts`) and
 * coloured relative to the brightest cell with the orders ramp
 * (`src/lib/heatRamp.ts`). The picture has one pixel per cell, and the page
 * stretches it over the minimap.
 */
final class HeatPicture
{
    /** `DEFAULT_RADIUS_FRACTION`: the blob's reach as a share of the map's shorter side. */
    public const RADIUS_FRACTION = 1 / 32;

    /** `SIGMA_PER_RADIUS`. */
    private const SIGMA_PER_RADIUS = 1 / 2.5;

    /** `HEAT_RAMPS.orders`, least to most. */
    public const RAMP = ['#00788f', '#00a0b8', '#0ac8dc', '#54e4f2', '#aaf6fc'];

    /** `HEAT_RAMPS.deaths`, which Coilbox's deaths and damage layers share. */
    public const DEATHS_RAMP = ['#7a3b00', '#b05a00', '#e07a00', '#ff9f40', '#ffd070'];

    private const THRESHOLD = 0.04;

    private const GAMMA = 0.5;

    private const ALPHA_MIN = 0.5;

    private const ALPHA_MAX = 0.9;

    /**
     * The smoothed field and what its legend says: how much was counted
     * within `radius` elmos of the brightest spot, such as orders aimed.
     *
     * @param  array{width: int, height: int, world_width: int, world_height: int, cells: list<array{0: int, 1: int|float}>}  $heat
     * @return array{values: list<float>, peak: float, radius: float, peak_within_radius: int|float, counted: int|float}
     */
    public static function field(array $heat): array
    {
        ['width' => $width, 'height' => $height, 'world_width' => $worldW, 'world_height' => $worldH] = $heat;
        $radius = min($worldW, $worldH) * self::RADIUS_FRACTION;
        $binned = array_fill(0, $width * $height, 0.0);
        $counted = 0;
        foreach ($heat['cells'] as [$cell, $count]) {
            $binned[$cell] += $count;
            $counted += $count;
        }
        $cellW = $worldW / $width;
        $cellH = $worldH / $height;
        $sigma = $radius * self::SIGMA_PER_RADIUS;
        $across = self::blur($binned, $height, $width, $width, 1, self::kernel($sigma / $cellW, $radius / $cellW));
        $values = self::blur($across, $width, $height, 1, $width, self::kernel($sigma / $cellH, $radius / $cellH));

        $peak = 0.0;
        $peakIndex = -1;
        foreach ($values as $i => $v) {
            if ($v > $peak) {
                $peak = $v;
                $peakIndex = $i;
            }
        }
        $within = 0;
        if ($peakIndex >= 0) {
            $px = (($peakIndex % $width) + 0.5) * $cellW;
            $pz = (intdiv($peakIndex, $width) + 0.5) * $cellH;
            foreach ($heat['cells'] as [$cell, $count]) {
                $x = (($cell % $width) + 0.5) * $cellW;
                $z = (intdiv($cell, $width) + 0.5) * $cellH;
                if (hypot($x - $px, $z - $pz) <= $radius) {
                    $within += $count;
                }
            }
        }

        return ['values' => $values, 'peak' => $peak, 'radius' => $radius, 'peak_within_radius' => $within, 'counted' => $counted];
    }

    /**
     * A layer's picture as a response, drawn once and kept.
     *
     * @param  array{heat: array<string, mixed>, ramp: list<string>}  $layer
     */
    public static function response(array $layer): Response
    {
        $png = HubCache::remember('replays:heat:'.md5(json_encode($layer)), [], HubCache::WEEKS, fn () => base64_encode(self::png($layer['heat'], $layer['ramp'])));

        return response(base64_decode($png), 200, [
            'Content-Type' => 'image/png',
            // The page's link carries the heat's hash, so a changed heat is a new address.
            'Cache-Control' => 'public, max-age='.HubCache::WEEKS,
        ]);
    }

    /**
     * The field as a PNG, one pixel per cell, transparent under the threshold,
     * coloured with `$ramp`.
     *
     * @param  array{width: int, height: int, world_width: int, world_height: int, cells: list<array{0: int, 1: int|float}>}  $heat
     * @param  list<string>  $ramp
     */
    public static function png(array $heat, array $ramp = self::RAMP): string
    {
        $field = self::field($heat);
        $image = imagecreatetruecolor($heat['width'], $heat['height']);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $peak = $field['peak'];
        foreach ($field['values'] as $i => $v) {
            $t = $peak > 0 ? $v / $peak : 0.0;
            if (! ($t > 0) || $t < self::THRESHOLD) {
                $colour = imagecolorallocatealpha($image, 0, 0, 0, 127);
            } else {
                $drawn = min(1.0, max(0.0, $t)) ** self::GAMMA;
                [$r, $g, $b] = self::rampAt($ramp, $drawn);
                $alpha = self::ALPHA_MIN + (self::ALPHA_MAX - self::ALPHA_MIN) * $drawn;
                $colour = imagecolorallocatealpha($image, $r, $g, $b, (int) round(127 - $alpha * 127));
            }
            imagesetpixel($image, $i % $heat['width'], intdiv($i, $heat['width']), $colour);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param list<string> $ramp @return array{0: int, 1: int, 2: int} */
    private static function rampAt(array $ramp, float $t): array
    {
        $at = $t * (count($ramp) - 1);
        $lo = (int) floor($at);
        $hi = min(count($ramp) - 1, $lo + 1);
        $f = $at - $lo;
        $a = sscanf($ramp[$lo], '#%02x%02x%02x');
        $b = sscanf($ramp[$hi], '#%02x%02x%02x');

        return array_map(fn ($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $f), [0, 1, 2]);
    }

    /** `kernelFor`: a Gaussian cut off at the reach, 1 at its centre. @return list<float> */
    private static function kernel(float $sigma, float $reach): array
    {
        $reach = max(0, (int) ceil($reach));
        $kernel = [];
        for ($i = -$reach; $i <= $reach; $i++) {
            $kernel[] = $sigma > 0 ? exp(-($i * $i) / (2 * $sigma * $sigma)) : 1.0;
        }

        return $kernel;
    }

    /**
     * `blurLines`: one pass of the kernel along every line.
     *
     * @param  list<float>  $from
     * @param  list<float>  $kernel
     * @return list<float>
     */
    private static function blur(array $from, int $lines, int $length, int $lineStep, int $step, array $kernel): array
    {
        $into = array_fill(0, count($from), 0.0);
        $reach = intdiv(count($kernel) - 1, 2);
        for ($line = 0; $line < $lines; $line++) {
            $base = $line * $lineStep;
            for ($i = 0; $i < $length; $i++) {
                $sum = 0.0;
                $hi = min($length - 1, $i + $reach);
                for ($j = max(0, $i - $reach); $j <= $hi; $j++) {
                    $sum += $from[$base + $j * $step] * $kernel[$j - $i + $reach];
                }
                $into[$base + $i * $step] = $sum;
            }
        }

        return $into;
    }
}
