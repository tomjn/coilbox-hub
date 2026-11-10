<?php

namespace App\Domain\Gallery\Workshop;

use App\Domain\Container\Container;
use App\Domain\Gallery\Art\Palette;
use App\Domain\Games\Cached;
use Collator;
use stdClass;

/**
 * What a published preset offers somebody who wants to play it with other
 * people: the autohost commands that rebuild it in a multiplayer lobby. Ported
 * from `lib/workshop/presetCommands.ts`.
 *
 * A preset is a skirmish snapshot, so this is a translation and not an export.
 * Every command is checked against SPADS. Two of its numbers count from one
 * where coilbox counts from zero: the team on `!addBox` and the number on
 * `!force <x> team`. Only the roster withholds the whole list, because half a
 * roster is a different game rather than a plainer one.
 *
 * Returns the same shape as {@see LobbyCommands::commands()}.
 */
final class PresetCommands
{
    /** Coilbox's sentinel for "roll a concrete side at launch", which a lobby cannot ask for. */
    private const RANDOM_SIDE = '__random__';

    /** Both ends of this agree: coilbox stores boxes on it and SPADS reads it. */
    private const GRID = 200;

    /** SPADS takes a team from 1 to 251 and subtracts one, so this is the highest ally team coilbox can hand it. */
    private const MAX_ALLY = 250;

    /** A game's factions as the hub holds them, for the collision check in {@see self::botSide()}. Empty when the hub has no catalog for the game. @return list<array{key: string, name: string}> */
    public static function factionsFor(string $gameKey): array
    {
        return Cached::gameFactions($gameKey);
    }

    /**
     * @param  mixed  $payload  The container payload from `Json::decode()`
     * @param  list<array{name: string}>  $factions
     */
    public static function commands(mixed $payload, array $factions = []): ?array
    {
        $preset = self::readPreset($payload);
        if ($preset === null) {
            return null;
        }

        $lines = ["!map {$preset['mapName']}"];
        if ($preset['startPosType'] !== null) {
            $lines[] = "!bSet startPosType {$preset['startPosType']}";
        }
        foreach ($preset['modOptions'] as [$key, $value]) {
            $lines[] = "!bSet {$key} {$value}";
        }

        // Boxes only mean something when the players choose, and `hAddBox`
        // refuses them outright in any other mode.
        $badBoxes = 0;
        if ($preset['startPosType'] === 2) {
            foreach ($preset['startRects'] as [$ally, $box]) {
                $sides = [$box['left'], $box['top'], $box['right'], $box['bottom']];
                $onGrid = true;
                foreach ($sides as $n) {
                    if ($n < 0 || $n > self::GRID) {
                        $onGrid = false;
                    }
                }
                if (! $onGrid || $box['left'] > $box['right'] || $box['top'] > $box['bottom']) {
                    $badBoxes++;

                    continue;
                }
                if (! Js::isInteger($ally) || $ally < 0 || $ally > self::MAX_ALLY) {
                    $badBoxes++;

                    continue;
                }
                $lines[] = '!addBox '.implode(' ', array_map(fn ($n) => Palette::num($n), $sides)).' '.Palette::num($ally + 1);
            }
        }

        $bots = array_values(array_filter($preset['participants'], fn ($p) => self::get($p, 'kind') === 'ai'));
        $withheld = [];
        $taken = [];
        $addBot = [];
        $force = [];
        $renamed = 0;
        $randomSides = 0;
        /** First words more than one of the game's factions start with, and how many bots lost their faction to each. */
        $contested = [];

        foreach ($bots as $bot) {
            $ai = self::get($bot, 'ai');
            $shortName = self::text(self::get($ai, 'shortName')) ?? '';
            if ($shortName === '' || preg_match('/^[^ ;][^;]*$/Du', $shortName) !== 1) {
                $withheld[] = 'One of the AI players does not say which AI it is, so the roster would be short a player.';
                break;
            }

            $label = self::firstNonEmpty(self::get($bot, 'name'), self::get($ai, 'name')) ?? $shortName;
            $name = self::botName($label, $taken);
            $taken[$name] = true;
            if ($name !== $label) {
                $renamed++;
            }

            $botSide = self::text(self::get($bot, 'side'));
            $side = self::botSide($botSide, $factions);
            if ($side === null && $botSide !== null && $botSide !== '') {
                if ($botSide === self::RANDOM_SIDE) {
                    $randomSides++;
                } else {
                    $word = self::firstWord($botSide);
                    $contested[$word] = ($contested[$word] ?? 0) + 1;
                }
            }

            $addBot[] = "!addBot {$name} ".($side ?? '0')." {$shortName}";
            $allyTeam = self::get($bot, 'allyTeam');
            $ally = $allyTeam ?? 0;
            if (Js::isInteger($ally) && $ally >= 0 && $ally <= self::MAX_ALLY) {
                // A bot arrives on team 0 whatever it was added for, so every one is placed.
                $force[] = "!force %{$name} team ".Palette::num($ally + 1);
            }
        }

        if ($force !== []) {
            // `hForce` refuses an id or a team outright while the host is balancing for itself.
            $lines[] = '!set autoBalance off';
        }
        array_push($lines, ...$addBot, ...$force);

        $shared = [];
        foreach ($preset['participants'] as $p) {
            $team = self::get($p, 'team');
            if (Js::isNumber($team)) {
                $key = Palette::num($team);
                $shared[$key] = ($shared[$key] ?? 0) + 1;
            }
        }

        $participants = $preset['participants'];
        $notCarried = [];
        if ($participants !== []) {
            $notCarried[] = 'Team colours. A lobby gives every bot the same colour and lets each person pick their own.';
        }
        foreach ($participants as $p) {
            $side = self::text(self::get($p, 'side'));
            if (self::get($p, 'kind') !== 'ai' && $side !== null && $side !== '' && $side !== self::RANDOM_SIDE) {
                $notCarried[] = "The faction each person plays. Only a bot's can be set from the chat.";
                break;
            }
        }
        if ($randomSides > 0) {
            $notCarried[] = "{$randomSides} bot".self::plural($randomSides).' had a random faction. A lobby cannot roll one, so '.($randomSides === 1 ? 'it gets' : 'they get').' the game\'s first. Set '.($randomSides === 1 ? 'it' : 'them').' in the lobby by hand.';
        }
        foreach ($contested as $word => $n) {
            $notCarried[] = "{$n} bot".self::plural($n).' get'.($n === 1 ? 's' : '')." the game's first faction, because this game has more than one starting \"{$word}\". Set ".($n === 1 ? 'it' : 'them').' in the lobby by hand.';
        }
        if ($renamed > 0) {
            $notCarried[] = "{$renamed} bot".self::plural($renamed).' '.($renamed === 1 ? 'was' : 'were')." renamed. A lobby only takes letters, digits and brackets in a bot's name.";
        }
        if (array_filter($shared, fn ($n) => $n > 1) !== []) {
            $notCarried[] = 'Players sharing control of one team. The host assigns those slots itself.';
        }
        if ($badBoxes > 0) {
            $notCarried[] = "{$badBoxes} start box".($badBoxes === 1 ? '' : 'es').' the grid does not allow, so that team picks anywhere.';
        }
        if ($preset['disabledUnits'] > 0) {
            $notCarried[] = "{$preset['disabledUnits']} disabled unit".self::plural($preset['disabledUnits']).', which no autohost command can restrict.';
        }
        if ($preset['advantage']) {
            $notCarried[] = "A resource advantage for the first team, which is not the same as a lobby's handicap.";
        }
        if ($preset['incomeMultiplier']) {
            $notCarried[] = 'A resource multiplier for the first team, which a lobby has no setting for.';
        }

        return [
            'kind' => 'preset',
            'lines' => $withheld !== [] ? [] : $lines,
            'withheld' => $withheld,
            'notCarried' => $notCarried,
            'chunks' => [],
            // A preset sets mod options as text values chosen in the app, not a
            // typed field a game's own Lua can turn into something else (issue #3121).
            'typedValuesNote' => null,
        ];
    }

    private static function plural(int $n): string
    {
        return $n === 1 ? '' : 's';
    }

    /** A string value, or null for anything else. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** The first value that is a non-empty string, as `a || b` picks it. */
    private static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** A field of a decoded object, or null for anything that is not one. */
    private static function get(mixed $value, string $key): mixed
    {
        return $value instanceof stdClass ? ($value->{$key} ?? null) : null;
    }

    /** `typeof value === "object" && value !== null && !Array.isArray(value)`, or an empty record. */
    private static function record(mixed $value): array
    {
        return $value instanceof stdClass ? Container::props($value) : [];
    }

    /** @return array{left: int|float, top: int|float, right: int|float, bottom: int|float}|null */
    private static function rect(mixed $value): ?array
    {
        $r = self::record($value);
        $sides = [];
        foreach (['left', 'top', 'right', 'bottom'] as $side) {
            if (! Js::isInteger($r[$side] ?? null)) {
                return null;
            }
            $sides[$side] = $r[$side];
        }

        return $sides;
    }

    /**
     * Null for anything that is not a preset coilbox wrote. The container's own
     * sniff asks the same three questions, and this adds that a nameless map
     * has nothing to set.
     */
    private static function readPreset(mixed $payload): ?array
    {
        $p = self::record($payload);
        if (! is_array($p['participants'] ?? null)) {
            return null;
        }
        if (! is_string($p['gameName'] ?? null) || ! is_string($p['mapName'] ?? null)) {
            return null;
        }
        if ($p['mapName'] === '') {
            return null;
        }

        $restrictions = self::record($p['restrictions'] ?? null);
        $disabled = $restrictions['disabledUnits'] ?? null;
        $startPosType = $p['startPosType'] ?? null;
        $advantage = $restrictions['advantage'] ?? null;
        $income = $restrictions['incomeMultiplier'] ?? null;

        $modOptions = [];
        foreach (Js::entries($p['modOptionValues'] ?? null) as [$key, $value]) {
            if ($key !== '' && is_string($value)) {
                $modOptions[] = [$key, $value];
            }
        }
        $collator = new Collator('en');
        usort($modOptions, fn ($a, $b) => $collator->compare($a[0], $b[0]));

        $startRects = [];
        foreach (Js::entries($p['startRects'] ?? null) as [$ally, $value]) {
            $box = self::rect($value);
            if ($box !== null) {
                $startRects[] = [Js::toNumber($ally), $box];
            }
        }

        return [
            'mapName' => $p['mapName'],
            // 0, 1 and 2 are every mode the engine defines. A newer coilbox's
            // mode says nothing rather than sending a number the host would reject.
            'startPosType' => Js::isNumber($startPosType) && in_array($startPosType, [0, 1, 2], false) ? (int) $startPosType : null,
            'modOptions' => $modOptions,
            'participants' => array_values($p['participants']),
            'startRects' => $startRects,
            'disabledUnits' => is_array($disabled) ? count($disabled) : 0,
            'advantage' => Js::isNumber($advantage) && $advantage != 0,
            'incomeMultiplier' => Js::isNumber($income) && $income != 1,
        ];
    }

    /**
     * The label a bot is added under, made to fit what a lobby accepts and kept
     * distinct from the bots already named. A name is cosmetic in a lobby, so
     * this changes one rather than refusing the preset.
     *
     * @param  array<string, true>  $taken
     */
    private static function botName(string $label, array $taken): string
    {
        $cleaned = substr((string) preg_replace('/[^A-Za-z0-9_\[\]]/u', '', $label), 0, 20);
        $base = strlen($cleaned) >= 2 ? $cleaned : 'Bot'.(count($taken) + 1);

        $name = $base;
        for ($n = 2; isset($taken[$name]); $n++) {
            $suffix = (string) $n;
            $name = substr($base, 0, 20 - strlen($suffix)).$suffix;
        }

        return $name;
    }

    private static function firstWord(string $side): string
    {
        return preg_split('/['.Js::WS.']+/u', $side)[0];
    }

    /**
     * The side to ask `!addBot` for. SPADS splits a command on spaces, but it
     * matches a side by prefix, so the first word of a two word faction reaches
     * the same faction. That goes wrong only when two of the game's factions
     * start on the same word, and then null means fall back to `0`.
     *
     * @param  list<array{name: string}>  $factions
     */
    private static function botSide(?string $side, array $factions): ?string
    {
        if ($side === null || $side === '' || $side === self::RANDOM_SIDE) {
            return null;
        }
        if (preg_match('/['.Js::WS.']/u', $side) !== 1) {
            return $side;
        }

        $first = self::firstWord($side);
        $shared = array_filter($factions, fn ($f) => str_starts_with(mb_strtolower($f['name'], 'UTF-8'), mb_strtolower($first, 'UTF-8')));

        return count($shared) > 1 ? null : $first;
    }
}
