<?php

namespace App\Domain\Gallery;

/**
 * What the item page reads off a payload to draw it, from the Next app's
 * `lib/gallery/presetPreview.ts`, `setupPackPreview.ts` and the small
 * previews in `components/ItemPreview.tsx`. Payloads are decoded JSON as
 * associative arrays.
 */
final class Previews
{
    private const RANDOM_SIDE = '__random__';

    /** `rgb(r g b)` from a participant's 0 to 1 colour. */
    public static function participantColorCss(mixed $color): string
    {
        $channels = is_array($color) && array_is_list($color) ? $color : [];
        $to = fn (mixed $v) => (int) round(min(1, max(0, is_numeric($v) ? (float) $v : 0)) * 255, 0, PHP_ROUND_HALF_UP);

        return 'rgb('.$to($channels[0] ?? 0).' '.$to($channels[1] ?? 0).' '.$to($channels[2] ?? 0).')';
    }

    /** @param array<string, mixed> $p */
    public static function participantLabel(array $p): string
    {
        if (($p['kind'] ?? null) === 'you') {
            return self::truthy($p['name'] ?? null) ? $p['name'] : 'You';
        }
        $ai = is_array($p['ai'] ?? null) ? $p['ai'] : [];
        foreach ([$ai['name'] ?? null, $ai['shortName'] ?? null, $p['name'] ?? null] as $candidate) {
            if (self::truthy($candidate)) {
                return (string) $candidate;
            }
        }

        return 'Open slot';
    }

    public static function participantSideLabel(mixed $side): ?string
    {
        if (! is_string($side) || $side === '') {
            return null;
        }

        return $side === self::RANDOM_SIDE ? 'Random' : $side;
    }

    /**
     * Who plays, grouped by ally team in team order. Null when nobody plays.
     *
     * @param  array<string, mixed>  $payload
     * @return array{teams: list<array{allyTeam: int|float, members: list<array<string, mixed>>}>, playingCount: int}|null
     */
    public static function presetComposition(array $payload): ?array
    {
        $participants = is_array($payload['participants'] ?? null) && array_is_list($payload['participants']) ? $payload['participants'] : [];
        $playing = array_values(array_filter($participants, fn ($p) => is_array($p) && ! self::truthy($p['spectator'] ?? null)));
        if ($playing === []) {
            return null;
        }
        $byTeam = [];
        foreach ($playing as $p) {
            $key = $p['allyTeam'] ?? 0;
            $byTeam[(string) $key]['allyTeam'] = $key;
            $byTeam[(string) $key]['members'][] = $p;
        }
        $teams = array_values($byTeam);
        usort($teams, fn ($a, $b) => $a['allyTeam'] <=> $b['allyTeam']);

        return ['teams' => $teams, 'playingCount' => count($playing)];
    }

    /** How a preset's start positions are chosen, for under its minimap. */
    public static function startPosNote(string $kind, mixed $container): ?string
    {
        $payload = is_array($container) ? ($container['payload'] ?? null) : null;
        if ($kind !== 'preset' || ! is_array($payload)) {
            return null;
        }

        return match ($payload['startPosType'] ?? null) {
            0 => 'Fixed map start positions',
            1 => 'Random start positions',
            2 => 'Players choose in game',
            default => null,
        };
    }

    /** @param array<string, mixed> $payload @return list<string> */
    public static function setupPackGameNames(array $payload): array
    {
        $games = is_array($payload['games'] ?? null) && array_is_list($payload['games']) ? $payload['games'] : [$payload['game'] ?? null];
        $names = [];
        foreach ($games as $game) {
            $name = is_array($game) ? ($game['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Every map a pack installs, each once. @return list<string> */
    public static function setupPackMaps(mixed $container): array
    {
        $payload = is_array($container) ? ($container['payload'] ?? null) : null;
        if (! is_array($payload)) {
            return [];
        }
        $maps = is_array($payload['maps'] ?? null) && array_is_list($payload['maps']) ? $payload['maps'] : [];

        return array_values(array_unique(array_filter($maps, fn ($name) => is_string($name) && $name !== '')));
    }

    /** The engine a pack pins, or null for none or the default `.spring`. @param array<string, mixed> $payload */
    public static function setupPackEngine(array $payload): ?string
    {
        $engine = $payload['engineVersion'] ?? null;

        return is_string($engine) && $engine !== '' && $engine !== '.spring' ? $engine : null;
    }

    /**
     * The building defs of a blueprint payload, or none when it is not one.
     * Only used to decide which pictures to look up, so a def the layout
     * would refuse costs a lookup and nothing else.
     *
     * @return list<string>
     */
    public static function blueprintDefs(mixed $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['name'] ?? null) || ! is_array($payload['buildings'] ?? null)) {
            return [];
        }
        $defs = [];
        foreach ($payload['buildings'] as $building) {
            if (is_array($building) && is_string($building['def'] ?? null) && $building['def'] !== '') {
                $defs[] = $building['def'];
            }
        }

        return $defs;
    }

    /**
     * A scenario's counts, leaving out what it has none of.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: int}>
     */
    public static function scenarioStats(array $payload): array
    {
        $scenario = is_array($payload['scenario'] ?? null) ? $payload['scenario'] : $payload;
        $stats = [];
        foreach (['Objectives' => 'objectives', 'Triggers' => 'triggers', 'Zones' => 'zones', 'Teams' => 'teams', 'Actors' => 'actors', 'Dialogue' => 'dialogue'] as $label => $key) {
            $value = $scenario[$key] ?? null;
            $n = is_array($value) ? count($value) : 0;
            if ($n > 0) {
                $stats[] = [$label, $n];
            }
        }

        return $stats;
    }

    /**
     * A challenge's numbers. Ascension only shows once somebody climbed it.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: string}>
     */
    public static function challengeStats(array $payload): array
    {
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $text = fn (string $key) => is_string($settings[$key] ?? null) ? $settings[$key] : null;
        $number = function (string $key) use ($settings): ?string {
            $value = $settings[$key] ?? 0;
            $n = is_numeric($value) ? (float) $value : (is_bool($value) ? (float) $value : 0.0);

            return $n == 0 || is_nan($n) ? null : self::jsNumber($n);
        };
        $stats = [
            // The enemy count, which the app's wizard calls "enemy factions".
            ['Systems', $number('nodeCount')],
            ['Enemies', $number('factionCount')],
            ['Layout', $text('layout')],
            ['Length', $text('length')],
            ['Difficulty', $number('difficulty')],
            ['Ascension', $number('ascension')],
        ];

        return array_values(array_filter($stats, fn ($stat) => $stat[1] !== null));
    }

    /** JavaScript's `String(n)` for the plain numbers a challenge holds. */
    private static function jsNumber(float $n): string
    {
        return $n == floor($n) && abs($n) < 1e21 ? (string) (int) $n : (string) $n;
    }

    /** JavaScript truthiness for a JSON value. */
    private static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }
}
