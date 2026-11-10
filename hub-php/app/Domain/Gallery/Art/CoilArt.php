<?php

namespace App\Domain\Gallery\Art;

/** Ported from `components/art/CoilArt.tsx`: one drawing as a full-bleed backdrop SVG. */
class CoilArt
{
    /** The full authored canvas height. Pool rects fill this even where viewHeight crops it. */
    private const HEIGHT = 200;

    public static function svg(string $id, float|int $strength = 1, ?string $class = null): string
    {
        $drawing = Drawings::get($id) ?? throw new \InvalidArgumentException("Unknown drawing '{$id}'");
        $palette = Palette::get();
        $n = fn (float|int $v): string => Palette::num($v);

        $defs = '';
        $rects = '';
        foreach ($drawing['pools'] as $i => $pool) {
            $defs .= '<radialGradient id="'.$id.'-pool'.$i.'" cx="'.$n($pool['cx']).'" cy="'.$n($pool['cy']).'" r="'.$n($pool['r']).'" gradientUnits="userSpaceOnUse">'
                .'<stop offset="0" stop-color="'.$palette['glow'].'" stop-opacity="'.$n($pool['opacity']).'"/>'
                .'<stop offset="1" stop-color="'.$palette['glow'].'" stop-opacity="0"/>'
                .'</radialGradient>';
            $rects .= '<rect width="'.Palette::WIDTH.'" height="'.self::HEIGHT.'" fill="url(#'.$id.'-pool'.$i.')"/>';
        }
        $inner = '<defs>'.$defs.'</defs>'.$rects.$drawing['paint']($palette, (float) $strength);

        return '<svg viewBox="0 0 '.Palette::WIDTH.' '.$drawing['viewHeight'].'" preserveAspectRatio="xMidYMax slice" role="img" aria-label="'
            .e($drawing['ariaLabel']).'"'
            .($class !== null && $class !== '' ? ' class="'.e($class).'"' : '')
            .'>'.$inner.'</svg>';
    }
}
