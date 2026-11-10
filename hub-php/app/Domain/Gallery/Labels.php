<?php

namespace App\Domain\Gallery;

use App\Domain\Container\Container;

/**
 * What to call a thing on screen, from the Next app's `lib/gallery/label.ts`.
 * A challenge's mode is its label, since warpath and conquest are separate
 * things to a player. Anything unrecognised shows as itself.
 */
final class Labels
{
    private const KIND = [
        'preset' => 'Preset',
        'challenge' => 'Challenge',
        'setup-pack' => 'Setup pack',
        'scenario' => 'Scenario',
        'blueprint' => 'Blueprint',
        'mod-project' => 'Project',
        'module' => 'Module',
    ];

    private const MODE = ['warpath' => 'Warpath', 'conquest' => 'Conquest'];

    private const KIND_PLURAL = [
        'preset' => 'Presets',
        'challenge' => 'Challenges',
        'setup-pack' => 'Setup packs',
        'scenario' => 'Scenarios',
        'blueprint' => 'Blueprints',
        'mod-project' => 'Projects',
        'module' => 'Modules',
    ];

    public static function item(string $kind, ?string $mode = null): string
    {
        if ($mode !== null && $mode !== '') {
            return self::MODE[$mode] ?? $mode;
        }

        return self::KIND[$kind] ?? $kind;
    }

    /** The plural, for a filter chip. */
    public static function kindPlural(string $kind): string
    {
        return self::KIND_PLURAL[$kind] ?? $kind;
    }

    /** "Presets, challenges, ... and modules", built from the kinds carried so it cannot fall behind them. */
    public static function kindsPlural(): string
    {
        $labels = array_map(self::kindPlural(...), Container::GALLERY_KINDS);
        $words = [$labels[0], ...array_map(self::lower(...), array_slice($labels, 1))];

        return self::joined($words, 'and');
    }

    public static function kindsPluralLower(): string
    {
        return self::lower(self::kindsPlural());
    }

    /** "preset, challenge, ... or module", to follow "a". */
    public static function kindsSingular(): string
    {
        return self::joined(array_map(fn ($kind) => self::lower(self::item($kind)), Container::GALLERY_KINDS), 'or');
    }

    /** @param list<string> $words */
    private static function joined(array $words, string $conjunction): string
    {
        $last = array_pop($words) ?? '';

        return $words !== [] ? implode(', ', $words).' '.$conjunction.' '.$last : $last;
    }

    private static function lower(string $label): string
    {
        return mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }
}
