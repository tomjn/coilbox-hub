<?php

namespace App\Domain\Gallery\Workshop;

/**
 * What a published project's page offers somebody who does not have coilbox:
 * the `!bset` lines to paste into a lobby, and the Lua behind them (issue
 * #418). Ported from `lib/workshop/lobbyCommands.ts`.
 *
 * Whether a particular game reads tweak slots is coilbox's to know. This
 * answers only for the project. Lines are withheld whole when any part of the
 * project would be missing from them, because a visitor pasting lines from a web
 * page cannot judge what was left out.
 *
 * The result is the same shape for a project and a preset:
 *   kind: 'project'|'preset'
 *   lines: list<string>
 *   withheld: list<string>
 *   notCarried: list<string>
 *   chunks: list<{form, title, reason, lua, unit}>
 *   typedValuesNote: ?string
 */
final class LobbyCommands
{
    private const TYPED_VALUES_NOTE = 'These lines write each value as typed. The game may load some of them as something else.';

    /**
     * Null when the payload is not a project coilbox could compile, or holds no
     * edit a slot can carry.
     *
     * @param  mixed  $payload  The container payload from `Json::decode()`
     */
    public static function commands(mixed $payload): ?array
    {
        $project = Project::read($payload);
        if ($project === null) {
            return null;
        }

        try {
            $compiled = Compile::compile($project);
        } catch (TooLarge) {
            return null;
        }
        if ($compiled['chunks'] === []) {
            return null;
        }

        $pack = BarPack::pack($compiled['chunks']);
        $withheld = [
            ...array_map(fn ($key) => "More than one copy in this project is named {$key}.", self::contestedNames($project['edits']['clones'])),
            ...self::missing($pack),
        ];

        $textEdits = $project['edits']['textEdits'];
        $leftBehind = count(array_filter($project['readOnlyLua'], fn ($block) => $block['form'] !== 'block'));
        $leftOut = count($compiled['leftOut']);
        $notCarried = array_values(array_filter([
            $textEdits > 0
                ? "{$textEdits} name or description edit".self::plural($textEdits).". An autohost command cannot change a unit's words."
                : null,
            // Only the ones left out. A carried block that parsed is in the lines above.
            $leftBehind > 0
                ? "{$leftBehind} block".self::plural($leftBehind).' of imported Lua that never parsed, so it is not in these lines.'
                : null,
            $leftOut > 0
                ? "{$leftOut} cop".($leftOut === 1 ? 'y' : 'ies').' with a name a unit cannot have.'
                : null,
        ], fn ($note) => $note !== null));

        return [
            'kind' => 'project',
            'lines' => $withheld !== [] ? [] : $pack['tweakdefs'],
            'withheld' => $withheld,
            'notCarried' => $notCarried,
            'chunks' => $compiled['chunks'],
            'typedValuesNote' => $withheld === [] && self::hasTypedFieldChanges($project) ? self::TYPED_VALUES_NOTE : null,
        ];
    }

    /**
     * Whether a field change in the lines could be a number a game's own Lua
     * rewrites while it loads. A copy's own fields are excluded: they are
     * written as a whole definition (issue #3121).
     */
    private static function hasTypedFieldChanges(array $project): bool
    {
        $edits = $project['edits'];
        foreach ($edits['overrides'] as $unit => $patch) {
            if (array_key_exists($unit, $edits['clones'])) {
                continue;
            }
            foreach ($patch as $value) {
                if (is_int($value) || is_float($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function plural(int $n): string
    {
        return $n === 1 ? '' : 's';
    }

    /** The copies that claim one unit name between them. Coilbox refuses to pack these. @return list<string> */
    private static function contestedNames(array $clones): array
    {
        $seen = [];
        foreach ($clones as $clone) {
            $seen[$clone['key']] = ($seen[$clone['key']] ?? 0) + 1;
        }

        return array_map('strval', array_keys(array_filter($seen, fn ($n) => $n > 1)));
    }

    /** @return list<string> */
    private static function missing(array $pack): array
    {
        return [
            ...array_map(fn ($title) => "\"{$title}\" is too long for one autohost command.", $pack['oversized']),
            ...array_map(fn ($title) => "\"{$title}\" did not fit in the slots a lobby has.", $pack['unplaced']),
        ];
    }
}
