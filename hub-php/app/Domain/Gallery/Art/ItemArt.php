<?php

namespace App\Domain\Gallery\Art;

/**
 * Ported from `lib/gallery/itemArt.ts`: which backdrop an item page shows, and
 * how strong. A kind this gallery does not carry yet falls back to downloads.
 */
class ItemArt
{
    private const KIND_DRAWING = [
        'preset' => 'skirmish',
        'scenario' => 'scenario',
        'setup-pack' => 'setup-packs',
        'blueprint' => 'blueprint',
    ];

    private const CHALLENGE_MODE_DRAWING = [
        'conquest' => 'conquest',
        'warpath' => 'warpath',
    ];

    /** Chosen by eye per drawing, since a canvas-wide mesh reads stronger than a subject. */
    private const STRENGTH = [
        'skirmish' => 0.16,
        'scenario' => 0.09,
        'setup-packs' => 0.045,
        'conquest' => 0.17,
        'warpath' => 0.08,
        'downloads' => 0.08,
        'blueprint' => 0.12,
    ];

    /** @return array{drawing: string, strength: float} */
    public static function for(string $kind, ?string $mode = null): array
    {
        $drawing = ($kind === 'challenge' && $mode ? (self::CHALLENGE_MODE_DRAWING[$mode] ?? null) : null)
            ?? self::KIND_DRAWING[$kind]
            ?? 'downloads';

        return ['drawing' => $drawing, 'strength' => self::STRENGTH[$drawing] ?? self::STRENGTH['downloads']];
    }
}
