<?php

namespace App\Domain\Og;

use GdImage;

/**
 * Draws the 1200 by 630 link preview cards with GD, in place of `next/og`.
 *
 * The positions follow what Satori worked out for the Next app's flex layout:
 * 72 pixels of padding, a kind label at the top, the title block in the
 * middle and a credit at the bottom, spaced evenly. Text is Geist Regular for
 * every line, as the reference renders it, because `next/og` bundles only that
 * weight and drew the 600 weight titles with it too.
 */
final class Card
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    private const PAD = 72;

    private const BACKGROUND = '#0b0b0d';

    private const TITLE_LIMIT = 80;

    /** The coil drawn at the top of every card, a 24 unit square path. */
    private const COIL = 'M12 3 18.62 7.39A1.3 1.3 0 0 1 19.2 8.56L18.76 15.13A1.3 1.3 0 0 1 18.03 16.21L12.68 18.79A1.3 1.3 0 0 1 11.38 18.69L6.99 15.68A1.3 1.3 0 0 1 6.44 14.5L6.84 9.82A1.3 1.3 0 0 1 7.6 8.75L11.31 7.06A1.3 1.3 0 0 1 12.61 7.2L15.39 9.24A1.3 1.3 0 0 1 15.91 10.45L15.56 13.24A1.3 1.3 0 0 1 14.75 14.28L12.72 15.09A1.3 1.3 0 0 1 11.4 14.88L10.22 13.89A1.3 1.3 0 0 1 9.79 12.61L10.04 11.47A1.09 1.09 0 0 1 10.66 10.71L11.34 10.4';

    /** Geist's ascender and its line height, `normal` in CSS, as fractions of the font size. */
    private const ASCENT = 1.005;

    private const NORMAL_LINE = 1.3;

    /** The hub's own card, `app/opengraph-image.tsx`: the coil above the name, centred. */
    public static function home(): string
    {
        $image = self::canvas();
        $textHeight = 64 * self::NORMAL_LINE;
        $top = (self::HEIGHT - (160 + 32 + $textHeight)) / 2;
        self::coil($image, (self::WIDTH - 160) / 2, $top, 160);
        $width = self::measure('Coilbox Hub', 64);
        self::text($image, 'Coilbox Hub', 64, '#fafafa', (self::WIDTH - $width) / 2, $top + 160 + 32 + 64 * self::ASCENT);

        return self::png($image);
    }

    /**
     * The card the item, map and game pages share: a label beside the coil,
     * the title with a line of facts under it, and a credit at the foot. A
     * logo, when given, sits left of the title block.
     *
     * @param  string|null  $logo  image bytes GD can decode
     */
    public static function standard(string $label, string $title, string $facts, string $credit, ?string $logo = null): string
    {
        $image = self::canvas();
        $left = self::PAD;
        $top = self::PAD;
        $bottom = self::HEIGHT - self::PAD;

        // The top row: a 48 pixel coil and the label centred against it.
        self::coil($image, $left, $top, 48);
        $labelTop = $top + (48 - 28 * self::NORMAL_LINE) / 2;
        self::text($image, $label, 28, '#8a8a8a', $left + 48 + 20, $labelTop + 28 * self::ASCENT);

        // The foot.
        $creditTop = $bottom - 26 * self::NORMAL_LINE;
        self::text($image, $credit, 26, '#6b6b6b', $left, $creditTop + 26 * self::ASCENT);

        // The middle block, spaced evenly between the two.
        $picture = $logo !== null ? self::decode($logo) : null;
        $textLeft = $picture !== null ? $left + 128 + 32 : $left;
        $lines = self::wrap(self::shorten($title), 68, self::WIDTH - self::PAD - $textLeft);
        $titleLine = 68 * 1.1;
        $column = count($lines) * $titleLine + ($facts !== '' ? 20 + 30 * self::NORMAL_LINE : 0);
        $block = $picture !== null ? max(128, $column) : $column;
        $space = $bottom - $top - 48 - 26 * self::NORMAL_LINE;
        $blockTop = $top + 48 + ($space - $block) / 2;

        if ($picture !== null) {
            self::logo($image, $picture, $left, $blockTop + ($block - 128) / 2);
        }
        $y = $blockTop + ($block - $column) / 2;
        foreach ($lines as $line) {
            // A line box shorter than the font's own, so the half leading is negative.
            self::text($image, $line, 68, '#fafafa', $textLeft, $y + ($titleLine - 68 * self::NORMAL_LINE) / 2 + 68 * self::ASCENT);
            $y += $titleLine;
        }
        if ($facts !== '') {
            self::text($image, $facts, 30, '#a3a3a3', $textLeft, $y + 20 + 30 * self::ASCENT);
        }

        return self::png($image);
    }

    /** The cut every card applies to a long title. */
    public static function shorten(string $title): string
    {
        return mb_strlen($title) > self::TITLE_LIMIT ? mb_substr($title, 0, self::TITLE_LIMIT).'…' : $title;
    }

    /**
     * Breaks text into lines no wider than `$width` at a size, at spaces where
     * it can and inside a word only when the word alone is too wide.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $px, float $width): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', self::collapse($text)) as $word) {
            $tried = $line === '' ? $word : $line.' '.$word;
            if (self::measure($tried, $px) <= $width) {
                $line = $tried;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = '';
            foreach (mb_str_split($word) as $char) {
                if ($line !== '' && self::measure($line.$char, $px) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $char;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private static function canvas(): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, self::colour($image, self::BACKGROUND));

        return $image;
    }

    /** Runs of white space drawn as one space, as the browser does. */
    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function font(): string
    {
        return resource_path('fonts/Geist-Regular.ttf');
    }

    /** GD sizes text in points at 96 dots an inch, so a CSS pixel size is three quarters of that. */
    private static function points(int $px): float
    {
        return $px * 0.75;
    }

    private static function measure(string $text, int $px): float
    {
        $box = imagettfbbox(self::points($px), 0, self::font(), $text);

        return $box === false ? 0 : $box[2] - $box[0];
    }

    private static function text(GdImage $image, string $text, int $px, string $hex, float $x, float $baseline): void
    {
        imagettftext($image, self::points($px), 0, (int) round($x), (int) round($baseline), self::colour($image, $hex), self::font(), self::collapse($text));
    }

    private static function colour(GdImage $image, string $hex, int $alpha = 0): int
    {
        return imagecolorallocatealpha($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), $alpha);
    }

    /**
     * The coil, stroked 2 units wide with round ends and joins. GD has no
     * smooth thick lines, so it is drawn four times larger by stamping discs
     * along the path, then scaled down.
     */
    private static function coil(GdImage $image, float $x, float $y, int $size): void
    {
        $scale = 4;
        $big = $size * $scale;
        $layer = imagecreatetruecolor($big, $big);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);
        $ink = self::colour($layer, '#fafafa');

        $unit = $big / 24;
        $radius = $unit; // half of the 2 unit stroke
        $points = Path::flatten(self::COIL);
        for ($i = 1; $i < count($points); $i++) {
            [$ax, $ay] = $points[$i - 1];
            [$bx, $by] = $points[$i];
            $steps = max(1, (int) ceil(hypot($bx - $ax, $by - $ay) * $unit));
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                imagefilledellipse($layer, (int) round(($ax + ($bx - $ax) * $t) * $unit), (int) round(($ay + ($by - $ay) * $t) * $unit), (int) round($radius * 2), (int) round($radius * 2), $ink);
            }
        }

        $small = imagecreatetruecolor($size, $size);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $layer, 0, 0, 0, 0, $size, $size, $big, $big);
        imagealphablending($image, true);
        imagecopy($image, $small, (int) round($x), (int) round($y), 0, 0, $size, $size);
    }

    private static function decode(string $bytes): ?GdImage
    {
        $picture = @imagecreatefromstring($bytes);

        return $picture === false ? null : $picture;
    }

    /** A logo fitted inside 128 pixels with its proportions kept, in a box with 16 pixel round corners. */
    private static function logo(GdImage $image, GdImage $picture, float $x, float $y): void
    {
        $box = 128;
        $w = imagesx($picture);
        $h = imagesy($picture);
        $fit = min($box / $w, $box / $h);
        $dw = max(1, (int) round($w * $fit));
        $dh = max(1, (int) round($h * $fit));

        $tile = imagecreatetruecolor($box, $box);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagealphablending($tile, true);
        imagecopyresampled($tile, $picture, (int) (($box - $dw) / 2), (int) (($box - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

        // Fade the corners out, with a pixel of soft edge.
        $r = 16;
        imagealphablending($tile, false);
        for ($py = 0; $py < $box; $py++) {
            for ($px = 0; $px < $box; $px++) {
                $cx = $px < $r ? $r : ($px >= $box - $r ? $box - $r : null);
                $cy = $py < $r ? $r : ($py >= $box - $r ? $box - $r : null);
                if ($cx === null || $cy === null) {
                    continue;
                }
                $coverage = max(0, min(1, $r - hypot($px + 0.5 - $cx, $py + 0.5 - $cy) + 0.5));
                if ($coverage >= 1) {
                    continue;
                }
                $rgba = imagecolorat($tile, $px, $py);
                $alpha = ($rgba >> 24) & 0x7F;
                $alpha = (int) round(127 - (127 - $alpha) * $coverage);
                imagesetpixel($tile, $px, $py, imagecolorallocatealpha($tile, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $alpha));
            }
        }

        imagealphablending($image, true);
        imagecopy($image, $tile, (int) round($x), (int) round($y), 0, 0, $box, $box);
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
