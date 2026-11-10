<?php

namespace App\Domain\Gallery\Art;

/**
 * Ported from `components/art/drawings.ts`. Each drawing is an array with
 * `id`, `ariaLabel`, `viewHeight`, `pools` and a `paint(palette, strength)`
 * closure that returns the subject's markup. Strength scales every opacity
 * down from Coilbox's card tuning so a drawing can sit behind running text.
 */
class Drawings
{
    /** One build square, in canvas units. */
    public const BLUEPRINT_PITCH = 20;

    /** Ground left clear on each side of a building, in canvas units. */
    public const BLUEPRINT_GAP = 2;

    private const BLUEPRINT_PLOTS = [
        [3, 1, 3, 3],
        [7, 1, 2, 2],
        [11, 1, 2, 3],
        [7, 4, 1, 1],
        [8, 4, 1, 1],
        [9, 4, 1, 1],
        [10, 4, 1, 1],
        [3, 5, 1, 1],
        [5, 5, 1, 1],
        [8, 6, 2, 2],
    ];

    /** The square the order has not reached yet. */
    private const BLUEPRINT_NEXT = [12, 5, 2, 2];

    /** Which plots the order thread passes through, as indices into the plots. */
    private const BLUEPRINT_ORDER = [0, 3, 9];

    /** @var array<string, array>|null */
    private static ?array $all = null;

    /**
     * Every drawing, keyed by `id`, in the order of `ALL_DRAWINGS`.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $list = [
            self::hub(), self::downloads(), self::setupPacks(), self::games(), self::archives(),
            self::skirmish(), self::scenario(), self::conquest(), self::warpath(), self::blueprint(),
        ];
        $keyed = [];
        foreach ($list as $drawing) {
            $keyed[$drawing['id']] = $drawing;
        }

        return self::$all = $keyed;
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    private static function n(float|int $v): string
    {
        return Palette::num($v);
    }

    /** Returns the `op` helper every paint function builds: round(v * strength). */
    private static function op(float $strength): \Closure
    {
        return fn (float|int $v): string => Palette::num(Palette::round($v * $strength));
    }

    private static function hub(): array
    {
        return [
            'id' => 'hub',
            'ariaLabel' => 'Five shapes above a dashed line, one crossing it onto a shelf that already holds two',
            'viewHeight' => 148,
            'pools' => [
                ['cx' => 160, 'cy' => 34, 'r' => 152, 'opacity' => 0.16],
                ['cx' => 160, 'cy' => 108, 'r' => 92, 'opacity' => 0.13],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $shared = '<polygon points="46,32 60,40 60,56 46,64 32,56 32,40"/>'
                    .'<rect x="92" y="20" width="34" height="26" rx="4"/>'
                    .'<circle cx="166" cy="46" r="16"/>'
                    .Palette::diamond(222, 30, 16)
                    .'<rect x="262" y="30" width="28" height="28" rx="4"/>';
                $held = '<rect x="96" y="98" width="28" height="24" rx="3"/>'
                    .'<circle cx="212" cy="110" r="12"/>';

                return '<g fill="'.$p['line'].'" fill-opacity="'.$op(0.5).'" stroke="'.$p['faint'].'" stroke-width="1.5" stroke-opacity="'.$op(0.65).'">'.$shared.'</g>'
                    .'<g fill="none" stroke="'.$p['faint'].'" stroke-width="1.5" stroke-opacity="'.$op(0.45).'" stroke-dasharray="6 9">'
                    .'<path d="M-10 78 L330 78"/>'
                    .'</g>'
                    .'<g fill="'.$p['line'].'" fill-opacity="'.$op(0.55).'" stroke="'.$p['faint'].'" stroke-width="1.2" stroke-opacity="'.$op(0.65).'">'.$held.'</g>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="2" stroke-opacity="'.$op(0.6).'" stroke-linecap="round" stroke-linejoin="round">'
                    .'<path d="M222 50 Q216 82 176 94"/>'
                    .'<path d="M188 84 L176 94 L191 96"/>'
                    .'</g>'
                    .'<g fill="'.$p['spark'].'" fill-opacity="'.$op(0.8).'">'.Palette::diamond(160, 107, 15).'</g>'
                    .'<rect x="84" y="126" width="152" height="4" rx="2" fill="'.$p['faint'].'" fill-opacity="'.$op(0.5).'"/>';
            },
        ];
    }

    private static function downloads(): array
    {
        return [
            'id' => 'downloads',
            'ariaLabel' => 'A package falling through chevrons into a tray that already holds two',
            'viewHeight' => 184,
            'pools' => [
                ['cx' => 160, 'cy' => 44, 'r' => 118, 'opacity' => 0.18],
                ['cx' => 160, 'cy' => 168, 'r' => 104, 'opacity' => 0.11],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $arrows = '';
                foreach ([40, 62, 84] as $i => $y) {
                    $arrows .= '<path d="M124 '.$y.' L160 '.($y + 22).' L196 '.$y.'" stroke-opacity="'.$op(Palette::round(0.1 + $i * 0.07)).'"/>';
                }
                $landed = '';
                foreach ([[126, 148], [154, 148]] as [$x, $y]) {
                    $landed .= '<rect x="'.$x.'" y="'.$y.'" width="22" height="18" rx="3" fill="'.$p['line'].'" fill-opacity="'.$op(0.2).'"/>';
                }

                return '<g fill="none" stroke="'.$p['line'].'" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">'
                    .$arrows
                    .'</g>'
                    .'<rect x="146" y="98" width="28" height="24" rx="4" fill="'.$p['line'].'" fill-opacity="'.$op(0.26).'"/>'
                    .'<rect x="146" y="98" width="28" height="24" rx="4" fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.42).'"/>'
                    .$landed
                    .'<g fill="none" stroke="'.$p['faint'].'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="'.$op(0.3).'">'
                    .'<path d="M104 140 L104 172 L216 172 L216 140"/>'
                    .'</g>'
                    .'<circle cx="160" cy="110" r="3" fill="'.$p['spark'].'" fill-opacity="'.$op(0.85).'"/>';
            },
        ];
    }

    private static function setupPacks(): array
    {
        return [
            'id' => 'setup-packs',
            'ariaLabel' => 'A hexagonal shell holding an assortment of shapes',
            'viewHeight' => 200,
            'pools' => [
                ['cx' => 160, 'cy' => 78, 'r' => 104, 'opacity' => 0.22],
                ['cx' => 160, 'cy' => 78, 'r' => 190, 'opacity' => 0.08],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="2" stroke-opacity="'.$op(0.34).'">'
                    .'<polygon points="160,12 246,58 246,122 160,168 74,122 74,58"/>'
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="1.5" stroke-opacity="'.$op(0.3).'">'
                    .'<polygon points="160,36 224,70 224,118 160,152 96,118 96,70"/>'
                    .'</g>'
                    .'<circle cx="132" cy="76" r="11" fill="'.$p['line'].'" fill-opacity="'.$op(0.3).'"/>'
                    .'<rect x="176" y="64" width="24" height="24" rx="4" fill="'.$p['line'].'" fill-opacity="'.$op(0.26).'"/>'
                    .'<g fill="'.$p['spark'].'" fill-opacity="'.$op(0.7).'">'.Palette::diamond(160, 118, 12).'</g>';
            },
        ];
    }

    private static function games(): array
    {
        return [
            'id' => 'games',
            'ariaLabel' => 'A shelf of cases, one pulled forward and lit',
            'viewHeight' => 150,
            'pools' => [
                ['cx' => 160, 'cy' => 74, 'r' => 134, 'opacity' => 0.17],
                ['cx' => 44, 'cy' => 138, 'r' => 84, 'opacity' => 0.09],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $cases = '';
                foreach ([0, 1, 2, 3, 4] as $i) {
                    $x = 46 + $i * 40;
                    $h = 82 + ($i % 3) * 8;
                    $y = self::n(Palette::round(128 - $h));
                    $cases .= '<rect x="'.$x.'" y="'.$y.'" width="30" height="'.$h.'" rx="3" fill="'.$p['line'].'" fill-opacity="'.$op(0.16).'"/>'
                        .'<rect x="'.$x.'" y="'.$y.'" width="30" height="'.$h.'" rx="3" fill="none" stroke="'.$p['faint'].'" stroke-width="1.2" stroke-opacity="'.$op(0.34).'"/>';
                }

                return $cases
                    .'<rect x="222" y="36" width="34" height="94" rx="4" fill="'.$p['line'].'" fill-opacity="'.$op(0.24).'"/>'
                    .'<rect x="222" y="36" width="34" height="94" rx="4" fill="none" stroke="'.$p['spark'].'" stroke-width="1.8" stroke-opacity="'.$op(0.55).'"/>'
                    .'<rect x="228" y="48" width="22" height="3" rx="1.5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.6).'"/>'
                    .'<rect x="228" y="56" width="14" height="3" rx="1.5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.4).'"/>'
                    .'<rect x="30" y="128" width="260" height="4" rx="2" fill="'.$p['faint'].'" fill-opacity="'.$op(0.32).'"/>';
            },
        ];
    }

    private static function archives(): array
    {
        return [
            'id' => 'archives',
            'ariaLabel' => 'Sealed drums stacked on a pallet',
            'viewHeight' => 160,
            'pools' => [
                ['cx' => 160, 'cy' => 70, 'r' => 118, 'opacity' => 0.18],
                ['cx' => 268, 'cy' => 132, 'r' => 84, 'opacity' => 0.09],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                // Coilbox's own drum leaves the two side paths without a
                // stroke-opacity. They get the ellipse's opacity here so they do
                // not read as stray full-strength seams over a dimmed backdrop.
                $drum = fn (int $cx, int $cy, int $rx, int $ry, float $o): string => '<ellipse cx="'.$cx.'" cy="'.$cy.'" rx="'.$rx.'" ry="'.$ry.'" stroke-opacity="'.$op($o).'"/>'
                    .'<path d="M'.($cx - $rx).' '.$cy.' L'.($cx - $rx).' '.($cy + 28).'" stroke-opacity="'.$op($o).'"/>'
                    .'<path d="M'.($cx + $rx).' '.$cy.' L'.($cx + $rx).' '.($cy + 28).'" stroke-opacity="'.$op($o).'"/>'
                    .'<ellipse cx="'.$cx.'" cy="'.($cy + 28).'" rx="'.$rx.'" ry="'.$ry.'" stroke-opacity="'.$op(Palette::round($o * 0.6)).'"/>';

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="1.8">'
                    .$drum(160, 26, 52, 15, 0.34)
                    .$drum(160, 62, 52, 15, 0.4)
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="2">'
                    .$drum(160, 98, 52, 15, 0.5)
                    .'</g>'
                    .'<rect x="90" y="140" width="140" height="5" rx="2.5" fill="'.$p['faint'].'" fill-opacity="'.$op(0.34).'"/>'
                    .'<circle cx="160" cy="98" r="5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.8).'"/>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.35).'">'
                    .'<path d="M132 126 L188 126"/>'
                    .'</g>';
            },
        ];
    }

    private static function skirmish(): array
    {
        return [
            'id' => 'skirmish',
            'ariaLabel' => 'Two wedges of chevrons facing each other across contoured ground',
            'viewHeight' => 200,
            'pools' => [
                ['cx' => 72, 'cy' => 108, 'r' => 130, 'opacity' => 0.16],
                ['cx' => 252, 'cy' => 92, 'r' => 120, 'opacity' => 0.14],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $ridge = fn (string $d, float $o): string => '<path d="'.$d.'" stroke-opacity="'.$op($o).'"/>';
                $chevron = fn (int $x, int $y, int $dir): string => '<path d="M'.($x - $dir * 4).' '.($y - 5).' L'.($x + $dir * 4).' '.$y.' L'.($x - $dir * 4).' '.($y + 5).'"/>';
                $wedge = function (int $tip, int $dir) use ($chevron): string {
                    $out = '';
                    foreach ([[0], [-16, 16], [-32, 0, 32]] as $col => $rank) {
                        foreach ($rank as $dy) {
                            $out .= $chevron($tip - $dir * $col * 18, 100 + $dy, $dir);
                        }
                    }

                    return $out;
                };

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="1.5">'
                    .$ridge('M-10 58 Q 74 40 146 54 T 330 44', 0.24)
                    .$ridge('M-10 148 Q 66 130 128 146 T 250 138 T 330 150', 0.3)
                    .$ridge('M-10 178 Q 88 162 168 174 T 330 166', 0.2)
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="'.$op(0.42).'">'
                    .$wedge(118, 1)
                    .$wedge(202, -1)
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.32).'" stroke-dasharray="7 9">'
                    .'<path d="M160 40 L160 164"/>'
                    .'</g>'
                    .'<circle cx="160" cy="100" r="4" fill="'.$p['spark'].'" fill-opacity="'.$op(0.7).'"/>';
            },
        ];
    }

    private static function scenario(): array
    {
        return [
            'id' => 'scenario',
            'ariaLabel' => 'A reticle over a perspective grid receding to a horizon',
            'viewHeight' => 200,
            'pools' => [
                ['cx' => 198, 'cy' => 92, 'r' => 118, 'opacity' => 0.19],
                ['cx' => 30, 'cy' => 190, 'r' => 130, 'opacity' => 0.08],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $horizon = 66;
                $lanes = '';
                foreach ([-70, 0, 70, 140, 210, 280, 350, 420] as $x) {
                    $lanes .= '<path d="M160 '.$horizon.' L'.$x.' 210"/>';
                }
                $bands = '';
                foreach ([96, 118, 146, 182] as $y) {
                    $bands .= '<path d="M-10 '.$y.' L330 '.$y.'"/>';
                }
                $ticks = '';
                foreach (['M198 30 L198 48', 'M198 136 L198 154', 'M136 92 L154 92', 'M242 92 L260 92'] as $d) {
                    $ticks .= '<path d="'.$d.'"/>';
                }

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="1" stroke-opacity="'.$op(0.22).'">'
                    .$lanes
                    .$bands
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="1.5" stroke-opacity="'.$op(0.45).'">'
                    .'<circle cx="198" cy="92" r="54"/>'
                    .'<circle cx="198" cy="92" r="34"/>'
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="2" stroke-opacity="'.$op(0.6).'" stroke-linecap="round">'
                    .$ticks
                    .'</g>'
                    .'<circle cx="198" cy="92" r="5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.85).'"/>';
            },
        ];
    }

    /** `Math.imul`: the low 32 bits of a 32 bit multiply, as an unsigned int. */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $high = ((($a >> 16) * $b) & 0xFFFF) << 16;

        return ((($a & 0xFFFF) * $b) + $high) & 0xFFFFFFFF;
    }

    /** mulberry32, copied from `src/conquest/rng.ts` in Coilbox. */
    public static function mulberry32(int $seed): \Closure
    {
        $a = $seed & 0xFFFFFFFF;

        return function () use (&$a): float {
            $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $a;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $t = ($t ^ ($t + self::imul($t ^ ($t >> 7), $t | 61))) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    private static function conquest(): array
    {
        return [
            'id' => 'conquest',
            'ariaLabel' => 'A galaxy: a lit core, orbital sweeps and a starfield',
            'viewHeight' => 200,
            'pools' => [
                ['cx' => 206, 'cy' => 88, 'r' => 62, 'opacity' => 0.3],
                ['cx' => 206, 'cy' => 88, 'r' => 150, 'opacity' => 0.12],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $rand = self::mulberry32(0x5CA1AB1E);
                $stars = '';
                for ($i = 0; $i < 46; $i++) {
                    $x = Palette::round($rand() * Palette::WIDTH);
                    $y = Palette::round($rand() * 200);
                    $r = Palette::round(0.6 + $rand() * 1.3);
                    $o = Palette::round(0.2 + $rand() * 0.45);
                    $stars .= '<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="'.self::n($r).'" fill-opacity="'.$op($o).'"/>';
                }
                $arms = '';
                foreach ([[128, 46], [96, 34], [62, 22]] as $i => [$rx, $ry]) {
                    $arms .= '<ellipse cx="206" cy="88" rx="'.$rx.'" ry="'.$ry.'" transform="rotate(-19 206 88)" stroke-opacity="'.$op(Palette::round(0.16 + $i * 0.09)).'"/>';
                }
                $flare = '';
                foreach (['M206 66 L206 110', 'M184 88 L228 88', 'M190 72 L222 104', 'M222 72 L190 104'] as $d) {
                    $flare .= '<path d="'.$d.'"/>';
                }

                return '<g fill="'.$p['line'].'">'.$stars.'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="1.5">'.$arms.'</g>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="1" stroke-opacity="'.$op(0.3).'">'.$flare.'</g>'
                    .'<circle cx="206" cy="88" r="7" fill="'.$p['spark'].'" fill-opacity="'.$op(0.9).'"/>'
                    .'<circle cx="86" cy="150" r="3.5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.55).'"/>'
                    .'<circle cx="46" cy="62" r="2.5" fill="'.$p['spark'].'" fill-opacity="'.$op(0.45).'"/>';
            },
        ];
    }

    private static function warpath(): array
    {
        return [
            'id' => 'warpath',
            'ariaLabel' => 'Tiers of nodes with branching routes climbing to one lit node',
            'viewHeight' => 200,
            'pools' => [
                ['cx' => 160, 'cy' => 74, 'r' => 96, 'opacity' => 0.22],
                ['cx' => 160, 'cy' => 200, 'r' => 150, 'opacity' => 0.1],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $tiers = [[160], [92, 160, 228], [66, 124, 196, 254], [160]];
                $y = fn (int $t): int => 170 - $t * 32;
                $edges = '';
                $nodes = '';
                $last = count($tiers) - 1;
                for ($t = 0; $t < $last; $t++) {
                    foreach ($tiers[$t] as $x) {
                        foreach ($tiers[$t + 1] as $nx) {
                            if (abs($nx - $x) < 78) {
                                $edges .= '<path d="M'.$x.' '.($y($t) - 7).' L'.$nx.' '.($y($t + 1) + 7).'"/>';
                            }
                        }
                    }
                }
                for ($t = 0; $t < $last; $t++) {
                    foreach ($tiers[$t] as $x) {
                        $nodes .= Palette::diamond($x, $y($t), 6);
                    }
                }

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="1.5" stroke-opacity="'.$op(0.3).'">'
                    .$edges
                    .'</g>'
                    .'<g fill="'.$p['line'].'" fill-opacity="'.$op(0.42).'">'.$nodes.'</g>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.4).'">'
                    .Palette::diamond(160, 74, 17)
                    .'</g>'
                    .'<g fill="'.$p['spark'].'" fill-opacity="'.$op(0.85).'">'.Palette::diamond(160, 74, 9).'</g>';
            },
        ];
    }

    private static function blueprint(): array
    {
        return [
            'id' => 'blueprint',
            'ariaLabel' => 'A base of different sized squares on a build grid, threaded in build order to one square not yet placed',
            'viewHeight' => 176,
            'pools' => [
                ['cx' => 150, 'cy' => 58, 'r' => 128, 'opacity' => 0.18],
                ['cx' => 254, 'cy' => 150, 'r' => 92, 'opacity' => 0.09],
            ],
            'paint' => function (array $p, float $strength): string {
                $op = self::op($strength);
                $pitch = self::BLUEPRINT_PITCH;
                $gap = self::BLUEPRINT_GAP;
                $at = fn (float|int $squares): float|int => $squares * $pitch;
                $rect = function (array $plot) use ($at, $gap): string {
                    [$col, $row, $wide, $deep] = $plot;

                    return '<rect x="'.self::n($at($col) + $gap).'" y="'.self::n($at($row) + $gap)
                        .'" width="'.self::n($at($wide) - $gap * 2).'" height="'.self::n($at($deep) - $gap * 2).'" rx="2"';
                };
                $middle = fn (array $plot): array => [
                    $at($plot[0] + $plot[2] / 2),
                    $at($plot[1] + $plot[3] / 2),
                ];

                $grid = '';
                for ($i = 0; $i < Palette::WIDTH / $pitch + 1; $i++) {
                    $grid .= '<path d="M'.self::n($at($i)).' 0 L'.self::n($at($i)).' 200"/>';
                }
                for ($i = 0; $i < 200 / $pitch + 1; $i++) {
                    $grid .= '<path d="M0 '.self::n($at($i)).' L'.Palette::WIDTH.' '.self::n($at($i)).'"/>';
                }
                $built = '';
                foreach (self::BLUEPRINT_PLOTS as $plot) {
                    $built .= $rect($plot).'/>';
                }
                $stops = [];
                foreach (self::BLUEPRINT_ORDER as $i) {
                    $stops[] = $middle(self::BLUEPRINT_PLOTS[$i]);
                }
                $stops[] = $middle(self::BLUEPRINT_NEXT);
                [$startX, $startY] = $stops[0];
                $path = implode(' L', array_map(fn ($s) => self::n($s[0]).' '.self::n($s[1]), $stops));

                return '<g fill="none" stroke="'.$p['faint'].'" stroke-width="0.75" stroke-opacity="'.$op(0.2).'">'
                    .$grid
                    .'</g>'
                    .'<g fill="none" stroke="'.$p['line'].'" stroke-width="1.4" stroke-opacity="'.$op(0.5).'">'
                    .$built
                    .'</g>'
                    .$rect(self::BLUEPRINT_NEXT).' fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.5).'" stroke-dasharray="5 5"/>'
                    .'<g fill="none" stroke="'.$p['spark'].'" stroke-width="1.5" stroke-opacity="'.$op(0.6).'" stroke-linecap="round" stroke-linejoin="round">'
                    .'<path d="M'.$path.'" stroke-dasharray="4 7"/>'
                    .'</g>'
                    .'<circle cx="'.self::n($startX).'" cy="'.self::n($startY).'" r="4" fill="'.$p['spark'].'" fill-opacity="'.$op(0.8).'"/>';
            },
        ];
    }
}
