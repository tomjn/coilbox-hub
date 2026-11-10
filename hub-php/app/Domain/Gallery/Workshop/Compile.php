<?php

namespace App\Domain\Gallery\Workshop;

use stdClass;

/**
 * Turning a published project into the Lua a game reads, ported from
 * `lib/workshop/compile.ts`, which is the chunk half of coilbox's `compile.rs`.
 * The hub writes no mutator archive, only the lines a host pastes into a lobby.
 *
 * A chunk is {form: 'table'|'block', title, reason, lua, unit: ?string}. Blocks
 * are written in the order they have to run in.
 */
final class Compile
{
    /**
     * @param  array  $project  The result of {@see Project::read()}
     * @return array{chunks: list<array>, leftOut: list<string>}
     *
     * @throws TooLarge for the one input that would cost more to compile than any slot could carry
     */
    public static function compile(array $project): array
    {
        $edits = $project['edits'];
        $chunks = [];
        $clones = array_map(fn ($pair) => $pair[1], Js::sorted($edits['clones']));

        $leftOut = array_values(array_map(fn ($c) => $c['key'], array_filter($clones, fn ($c) => ! self::validUnitKey($c['key']))));
        $usable = array_values(array_filter($clones, fn ($c) => self::validUnitKey($c['key'])));

        // Lua the project carries but cannot edit goes first, so everything the
        // author did in coilbox lands on top of the baseline they started from.
        foreach ($project['readOnlyLua'] as $block) {
            if ($block['form'] !== 'block') {
                continue;
            }
            $chunks[] = [
                'form' => 'block',
                'title' => $block['title'],
                'reason' => 'Carried from a decoded import as it stands. Coilbox never runs it, and cannot edit it either, so it is written out the way it arrived.',
                'lua' => $block['lua'],
                'unit' => null,
            ];
        }

        // A copy under a name nothing else uses is assigned, not merged. BAR's
        // tweakunits route drops a key naming a unit the game does not have.
        $added = array_values(array_filter($usable, fn ($c) => ! $c['replacesGameUnit']));
        if ($added !== []) {
            $chunks[] = [
                'form' => 'block',
                'title' => count($added).' unit'.self::plural(count($added)).' added',
                'reason' => 'A copy owns its whole definition, and the game has no unit of that name to merge onto, so it has to be assigned rather than merged.',
                'lua' => self::addedBlock(array_map(fn ($c) => [$c['key'], Lua::literal(self::resolvedCloneDef($c, $edits), '    ')], $added)),
                'unit' => self::singletonUnit(array_map(fn ($c) => $c['key'], $added)),
            ];
        }

        // A copy under a name the game already uses lands after the game's own
        // definitions and replaces them, so the table form cannot carry it.
        foreach (array_filter($usable, fn ($c) => $c['replacesGameUnit']) as $clone) {
            $chunks[] = [
                'form' => 'block',
                'title' => $clone['key'].' replaced',
                'reason' => "The game defines {$clone['key']} too, so this has to be written after its definitions have loaded, and it replaces rather than merges.",
                'lua' => self::replaceBlock($clone, self::resolvedCloneDef($clone, $edits)),
                'unit' => $clone['key'],
            ];
        }

        // Field changes against the game's own units, a patch and nothing more.
        // A change through a list position is held apart, because only the
        // game's own table says which entry the step means (issue #3041).
        $patches = [];
        $positional = [];
        $fields = 0;
        foreach (Js::sorted($edits['overrides']) as [$unit, $patch]) {
            if (array_key_exists($unit, $edits['clones'])) {
                continue; // Folded into the copy's own definition.
            }
            $tree = new PatchTree;
            foreach (Js::sorted($patch) as [$path, $value]) {
                if (self::throughAPosition($path)) {
                    $positional[] = [$unit, $path, $value];
                } else {
                    $tree->insert($path, $value);
                    $fields++;
                }
            }
            if (! $tree->isEmpty()) {
                $patches[] = [$unit, $tree->toLua('  ')];
            }
        }
        if ($patches !== []) {
            $chunks[] = [
                'form' => 'table',
                'title' => $fields.' field change'.self::plural($fields),
                'reason' => 'Each one is a value the user typed, so none of them has to read the game\'s own first.',
                'lua' => self::table($patches),
                'unit' => self::singletonUnit(array_map(fn ($p) => $p[0], $patches)),
            ];
        }
        if ($positional !== []) {
            $chunks[] = [
                'form' => 'block',
                'title' => count($positional).' field change'.self::plural(count($positional)).' through a list',
                'reason' => 'A list with a gap in it, such as weapons 1 and 3 and no 2, keeps its own numbers, so which entry each change is for has to be read off the game\'s own list.',
                'lua' => self::positionalBlock($positional),
                'unit' => self::singletonUnit(array_map(fn ($p) => $p[0], $positional)),
            ];
        }

        foreach (Js::sorted($edits['menus']) as [$builder, $ops]) {
            if (array_key_exists($builder, $edits['clones']) || $ops === []) {
                continue;
            }
            $chunks[] = [
                'form' => 'block',
                'title' => $builder.' build menu',
                'reason' => 'Replayed over the list the game ships, so a unit it adds to this builder later is still there.',
                'lua' => self::menuBlock($builder, $ops),
                'unit' => $builder,
            ];
        }

        // Last, so it reaches every list the blocks above left behind.
        if ($edits['disabled'] !== []) {
            $n = count($edits['disabled']);
            $chunks[] = [
                'form' => 'block',
                'title' => $n.' unit'.self::plural($n).' switched off',
                'reason' => 'It reads every builder in the game before it writes to any of them.',
                'lua' => self::disabledBlock($edits['disabled']),
                'unit' => self::singletonUnit($edits['disabled']),
            ];
        }

        return ['chunks' => $chunks, 'leftOut' => $leftOut];
    }

    /** A table-form chunk as a `do ... end` block, for the numbered tweak slots (issue #3126). */
    public static function tableAsBlock(string $table): string
    {
        return implode("\n", [
            'do',
            '-- Field changes. Only the fields the project set are here, so everything',
            '-- else still follows the game when it updates.',
            "local changes = {$table}",
            '',
            'local function merge(dest, src)',
            '  for key, value in pairs(src) do',
            '    if type(value) == "table" and type(dest[key]) == "table" then',
            '      merge(dest[key], value)',
            '    else',
            '      dest[key] = value',
            '    end',
            '  end',
            'end',
            '',
            '-- A unit the game no longer has is skipped rather than created: a patch is a',
            '-- change to a definition, and half of one is not a unit.',
            'for name, patch in pairs(changes) do',
            '  local def = UnitDefs[name]',
            '  if def then',
            '    merge(def, patch)',
            '  end',
            'end',
            'end',
        ]);
    }

    private static function plural(int $n): string
    {
        return $n === 1 ? '' : 's';
    }

    /** The one name every item shares, or null when there is more than one or none. */
    private static function singletonUnit(array $units): ?string
    {
        $distinct = array_unique(array_map('strval', $units));

        return count($distinct) === 1 ? (string) $units[0] : null;
    }

    /** Text safe to put in a `--` comment: one line, no control characters. */
    private static function commentText(string $text): string
    {
        return (string) preg_replace('/\p{Cc}/u', ' ', $text);
    }

    private static function validUnitKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_]+$/D', $key) === 1;
    }

    /** A field path that goes through a list position: a step of nothing but digits. */
    private static function throughAPosition(string $path): bool
    {
        foreach (explode('.', $path) as $step) {
            if ($step !== '' && preg_match('/^[0-9]+$/D', $step) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A digit step as the Lua number it is written as, or a string when it is too large for a u64. */
    private static function stepLiteral(string $step): string
    {
        if (preg_match('/^[0-9]+$/D', $step) === 1) {
            $digits = ltrim($step, '0');
            $digits = $digits === '' ? '0' : $digits;
            if (strlen($digits) < 20 || (strlen($digits) === 20 && strcmp($digits, '18446744073709551615') <= 0)) {
                return $digits;
            }
        }

        return Lua::string($step);
    }

    private static function positionalBlock(array $changes): string
    {
        $entries = array_map(function ($change) {
            [$unit, $path, $value] = $change;
            $steps = array_map(fn ($s) => self::stepLiteral($s), explode('.', $path));

            return '    { '.Lua::string($unit).', { '.implode(', ', $steps).' }, '.Lua::literal($value, '    ').' },';
        }, $changes);

        return implode("\n", [
            "-- Field changes through a list position, matched against the game's own",
            '-- list when it loads.',
            'do',
            '  local changes = {',
            implode("\n", $entries),
            '  }',
            '  local function key(list, at)',
            '    local count = 0',
            '    for _ in pairs(list) do count = count + 1 end',
            '    if count == 0 or #list == count then return at + 1 end',
            '    if list[at] == nil and list[tostring(at)] ~= nil then return tostring(at) end',
            '    return at',
            '  end',
            '  local function merge(dest, src)',
            '    for k, v in pairs(src) do',
            '      if type(v) == "table" and type(dest[k]) == "table" then',
            '        merge(dest[k], v)',
            '      else',
            '        dest[k] = v',
            '      end',
            '    end',
            '  end',
            '  for _, change in ipairs(changes) do',
            '    local target, steps, value = UnitDefs[change[1]], change[2], change[3]',
            '    for i = 1, #steps do',
            '      if type(target) ~= "table" or value == nil then break end',
            '      local step = steps[i]',
            '      if type(step) == "number" then step = key(target, step) end',
            '      if i < #steps then',
            '        if type(target[step]) ~= "table" then target[step] = {} end',
            '        target = target[step]',
            '      elseif type(value) == "table" and type(target[step]) == "table" then',
            '        merge(target[step], value)',
            '      else',
            '        target[step] = value',
            '      end',
            '    end',
            '  end',
            'end',
        ]);
    }

    /** The array index a step names, or null. An index no slot could hold is refused before it is allocated. */
    private static function arrayIndex(string $step): ?int
    {
        if (preg_match('/^[0-9]+$/D', $step) !== 1) {
            return null;
        }
        if ((float) $step > BarPack::PAYLOAD_CAP) {
            throw new TooLarge;
        }

        return (int) $step;
    }

    /**
     * Write a dotted path into a definition, creating whatever it has to pass
     * through. A step of digits is an array index counted from zero. Returns
     * the container, which is a new one when `$target` was the wrong kind.
     *
     * @param  list<string>  $steps
     */
    private static function writePath(mixed $target, array $steps, mixed $value): mixed
    {
        $step = $steps[0];
        $rest = array_slice($steps, 1);
        $index = self::arrayIndex($step);

        if (is_array($target)) {
            $container = $index === null ? new stdClass : $target;
        } elseif ($target instanceof stdClass) {
            $container = $target;
        } else {
            $container = $index === null ? new stdClass : [];
        }

        if (is_array($container)) {
            while (count($container) <= $index) {
                $container[] = null;
            }
            $container[$index] = $rest === [] ? $value : self::writePath($container[$index], $rest, $value);

            return $container;
        }

        // PHP cannot hold a property named from a NUL byte. No editor writes one.
        if (str_starts_with($step, "\0")) {
            throw new TooLarge;
        }
        $held = property_exists($container, $step) ? $container->{$step} : null;
        $container->{$step} = $rest === [] ? $value : self::writePath($held, $rest, $value);

        return $container;
    }

    /** @return array{string, mixed}|null The key as written and its value */
    private static function buildOptionsEntry(mixed $def): ?array
    {
        if (! $def instanceof stdClass) {
            return null;
        }
        foreach (Js::sorted(get_object_vars($def)) as [$key, $value]) {
            if (mb_strtolower($key, 'UTF-8') === 'buildoptions') {
                return [$key, $value];
            }
        }

        return null;
    }

    /** The build list a definition declares, lower cased and de-duplicated. An object is read in numeric key order. */
    private static function buildOptionsOf(mixed $def): array
    {
        $raw = self::buildOptionsEntry($def)[1] ?? null;
        $entries = [];
        if (is_array($raw)) {
            $entries = array_values($raw);
        } elseif ($raw instanceof stdClass) {
            $numbered = [];
            foreach (Js::sorted(get_object_vars($raw)) as [$key, $value]) {
                if (preg_match('/^[+-]?[0-9]+$/D', $key) === 1) {
                    $numbered[] = [(float) $key, $value];
                }
            }
            usort($numbered, fn ($a, $b) => $a[0] <=> $b[0]);
            $entries = array_map(fn ($pair) => $pair[1], $numbered);
        }
        $out = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $unit = mb_strtolower(Js::trim($entry), 'UTF-8');
            if ($unit !== '' && ! in_array($unit, $out, true)) {
                $out[] = $unit;
            }
        }

        return $out;
    }

    /** Replay a menu's operations over a list. Used only for a copy the project owns. */
    private static function applyBuildMenu(array $inherited, array $ops): array
    {
        $list = $inherited;
        foreach ($ops as $op) {
            if ($op['op'] === 'add') {
                if (! in_array($op['unit'], $list, true)) {
                    $list[] = $op['unit'];
                }
            } elseif ($op['op'] === 'remove') {
                $list = array_values(array_filter($list, fn ($entry) => $entry !== $op['unit']));
            } else {
                $at = array_search($op['unit'], $list, true);
                if ($at === false) {
                    continue;
                }
                array_splice($list, $at, 1);
                $before = $op['before'] === null ? false : array_search($op['before'], $list, true);
                if ($before === false) {
                    $list[] = $op['unit'];
                } else {
                    array_splice($list, $before, 0, [$op['unit']]);
                }
            }
        }

        return $list;
    }

    /** A copy's definition as it will be read: its own definition, the project's later edits written in, and its build list resolved. */
    private static function resolvedCloneDef(array $clone, array $edits): mixed
    {
        $def = Js::copy($clone['def']);
        $patch = $edits['overrides'][$clone['key']] ?? null;
        if ($patch !== null) {
            foreach (Js::sorted($patch) as [$path, $value]) {
                $def = self::writePath($def, explode('.', $path), Js::copy($value));
            }
        }
        $ops = $edits['menus'][$clone['key']] ?? null;
        if ($ops !== null) {
            $key = self::buildOptionsEntry($def)[0] ?? 'buildoptions';
            $def = self::writePath($def, [$key], self::applyBuildMenu(self::buildOptionsOf($def), $ops));
        }

        return $def;
    }

    /** @param list<array{string, string}> $entries */
    private static function table(array $entries): string
    {
        $lines = array_map(fn ($e) => '  ['.Lua::string($e[0]).'] = '.$e[1].',', $entries);

        return "{\n".implode("\n", $lines)."\n}";
    }

    /** Every unit a project adds, as assignments in one loop, because the slot has a size cap. @param list<array{string, string}> $entries */
    private static function addedBlock(array $entries): string
    {
        $body = implode("\n", array_map(fn ($e) => '    ['.Lua::string($e[0]).'] = '.$e[1].',', $entries));

        return implode("\n", [
            '-- Units added. Assigned directly: none of these existed before, so there is',
            '-- nothing to merge onto.',
            'do',
            "  local added = {\n{$body}\n  }",
            '  for name, def in pairs(added) do',
            '    UnitDefs[name] = def',
            '  end',
            'end',
        ]);
    }

    /** A whole definition standing in for one the game already loaded. */
    private static function replaceBlock(array $clone, mixed $def): string
    {
        $from = $clone['source'] === null ? '' : ' Copied from '.self::commentText($clone['source']).'.';
        $key = Lua::string($clone['key']);

        return "-- Replaces the game's own {$key}.{$from}\ndo\n  UnitDefs[{$key}] = ".Lua::literal($def, '  ')."\nend";
    }

    /** One builder's menu, replayed over whatever the game ships. Stands on its own, because the packer may put each block in a slot of its own. */
    private static function menuBlock(string $builder, array $ops): string
    {
        $lines = [
            '-- Build menu for '.self::commentText($builder).'.',
            'do',
            '  local def = UnitDefs['.Lua::string($builder).']',
            '  if def then',
            '    local key = "buildoptions"',
            '    if def[key] == nil then',
            '      for k in pairs(def) do',
            '        if string.lower(k) == "buildoptions" then',
            '          key = k',
            '          break',
            '        end',
            '      end',
            '    end',
            '    local list = {}',
            '    if type(def[key]) == "table" then',
            '      local keys = {}',
            '      for i in pairs(def[key]) do',
            '        if type(i) == "number" then',
            '          keys[#keys + 1] = i',
            '        end',
            '      end',
            '      table.sort(keys)',
            '      for _, i in ipairs(keys) do',
            '        list[#list + 1] = def[key][i]',
            '      end',
            '    end',
            '    local function at(unit)',
            '      for i = 1, #list do',
            '        if string.lower(tostring(list[i])) == unit then',
            '          return i',
            '        end',
            '      end',
            '    end',
        ];
        foreach ($ops as $op) {
            $unit = Lua::string($op['unit']);
            if ($op['op'] === 'add') {
                array_push($lines, "    if not at({$unit}) then", "      list[#list + 1] = {$unit}", '    end');
            } elseif ($op['op'] === 'remove') {
                array_push($lines, '    do', "      local i = at({$unit})", '      if i then', '        table.remove(list, i)', '      end', '    end');
            } else {
                array_push($lines, '    do', "      local i = at({$unit})", '      if i then', '        local moved = table.remove(list, i)');
                if ($op['before'] === null) {
                    $lines[] = '        list[#list + 1] = moved';
                } else {
                    array_push(
                        $lines,
                        '        local before = at('.Lua::string($op['before']).')',
                        '        if before then',
                        '          table.insert(list, before, moved)',
                        '        else',
                        '          list[#list + 1] = moved',
                        '        end',
                    );
                }
                array_push($lines, '      end', '    end');
            }
        }
        array_push($lines, '    def[key] = list', '  end', 'end');

        return implode("\n", $lines);
    }

    /** Every unit the project switches off, taken out of every build list. */
    private static function disabledBlock(array $disabled): string
    {
        return implode("\n", [
            '-- Units switched off, taken out of every build menu in the game.',
            'do',
            '  local off = {',
            ...array_map(fn ($unit) => '    ['.Lua::string($unit).'] = true,', $disabled),
            '  }',
            '  for _, def in pairs(UnitDefs) do',
            '    for key, list in pairs(def) do',
            '      if string.lower(key) == "buildoptions" and type(list) == "table" then',
            '        local keys = {}',
            '        for i in pairs(list) do',
            '          if type(i) == "number" then',
            '            keys[#keys + 1] = i',
            '          end',
            '        end',
            '        table.sort(keys)',
            '        local kept = {}',
            '        for _, i in ipairs(keys) do',
            '          if not off[string.lower(tostring(list[i]))] then',
            '            kept[#kept + 1] = list[i]',
            '          end',
            '        end',
            '        def[key] = kept',
            '      end',
            '    end',
            '  end',
            'end',
        ]);
    }
}
