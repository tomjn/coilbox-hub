/**
 * Turning a published project into the Lua a game reads.
 *
 * A port of the chunk half of coilbox's
 * `crates/tauri-plugin-coilbox-workshop/src/compile.rs`. The mutator archive,
 * its `modinfo.lua` and its language files are not here: the hub writes no
 * archive, only the lines a host pastes into a lobby (issue #418).
 *
 * There are two forms of chunk and they go to different slots. A table is a
 * plain map of unit name to definition, which is what `tweakunits` carries. A
 * block is executable Lua wrapped in `do ... end`, run with `UnitDefs` in
 * scope, which is what `tweakdefs` carries. The compiler picks per change, and
 * the order it writes blocks in is the order they have to run in.
 *
 * It never sees the game, which is what lets the hub run it at all: every
 * decision is a fact about the project.
 *
 * `barPack.test.ts` runs this over the projects in a fixture coilbox's own
 * tests write, and expects the same chunks, word for word.
 */

import { PAYLOAD_CAP } from "./barPack";
import { compareKeys, type Json, luaLiteral, luaString, PatchTree } from "./lua";
import type { BuildMenuOp, GameEdits, ModProject, UnitClone } from "./project";

export interface Chunk {
  form: "table" | "block";
  /** What this chunk changes, as a heading. */
  title: string;
  /** Why the compiler wrote it in this form and not the other. */
  reason: string;
  lua: string;
}

export interface CompiledProject {
  chunks: Chunk[];
  /** Copies left out because their key cannot be a unit's internal name. */
  leftOut: string[];
}

/** A project whose own edits would build something no slot could hold. */
export class TooLarge extends Error {}

function sorted<T>(map: Map<string, T>): Array<[string, T]> {
  return [...map].sort(([a], [b]) => compareKeys(a, b));
}

function plural(n: number): string {
  return n === 1 ? "" : "s";
}

/** Text that is safe to put in a `--` comment: one line, no control
 *  characters. A newline inside one would end the comment and leave whatever
 *  came after it as Lua the game runs. */
function commentText(text: string): string {
  return text.replace(/\p{Cc}/gu, " ");
}

/** Whether a key can be a unit's internal name. */
function validUnitKey(key: string): boolean {
  return /^[a-z0-9_]+$/.test(key);
}

function arrayIndex(step: string): number | null {
  if (!/^[0-9]+$/.test(step)) return null;
  const index = Number(step);
  // A definition padded out to this index could not fit a slot even if every
  // entry before it were one character, so nothing is lost by stopping here,
  // and a payload naming index 4000000000 does not get to allocate it.
  if (index > PAYLOAD_CAP) throw new TooLarge();
  return index;
}

/** Write a dotted path into a definition, creating whatever it has to pass
 *  through. A step of digits is an array index counted from zero. Returns the
 *  container, which is a new one when `target` was the wrong kind. */
function writePath(target: Json, steps: string[], value: Json): Json {
  const [step, ...rest] = steps;
  const index = arrayIndex(step);
  let container: Json[] | { [key: string]: Json };
  if (Array.isArray(target)) {
    container = index === null ? {} : target;
  } else if (typeof target === "object" && target !== null) {
    container = target;
  } else {
    container = index === null ? {} : [];
  }

  if (Array.isArray(container)) {
    const at = index as number;
    while (container.length <= at) container.push(null);
    container[at] = rest.length === 0 ? value : writePath(container[at], rest, value);
  } else {
    const held = Object.hasOwn(container, step) ? container[step] : null;
    // `defineProperty` so a step named `__proto__` is a key like any other.
    Object.defineProperty(container, step, {
      value: rest.length === 0 ? value : writePath(held, rest, value),
      enumerable: true,
      writable: true,
      configurable: true,
    });
  }
  return container;
}

function buildOptionsEntry(def: Json): [string, Json] | null {
  if (typeof def !== "object" || def === null || Array.isArray(def)) return null;
  const key = Object.keys(def)
    .sort(compareKeys)
    .find((k) => k.toLowerCase() === "buildoptions");
  return key === undefined ? null : [key, def[key]];
}

/** The build list a definition declares, lower cased and de-duplicated. An
 *  object is taken in numeric key order, which is what an empty Lua table
 *  comes back as. */
function buildOptionsOf(def: Json): string[] {
  const raw = buildOptionsEntry(def)?.[1];
  let entries: Json[] = [];
  if (Array.isArray(raw)) {
    entries = raw;
  } else if (typeof raw === "object" && raw !== null) {
    entries = Object.keys(raw)
      .sort(compareKeys)
      .filter((key) => /^[+-]?[0-9]+$/.test(key))
      .map((key): [number, Json] => [Number(key), raw[key]])
      .sort(([a], [b]) => a - b)
      .map(([, value]) => value);
  }
  const out: string[] = [];
  for (const entry of entries) {
    if (typeof entry !== "string") continue;
    const unit = entry.trim().toLowerCase();
    if (unit !== "" && !out.includes(unit)) out.push(unit);
  }
  return out;
}

/** Replay a menu's operations over a list. Used only for a copy the project
 *  owns: a game unit's menu is replayed by the generated Lua at load time. */
function applyBuildMenu(inherited: string[], ops: BuildMenuOp[]): string[] {
  let list = [...inherited];
  for (const op of ops) {
    if (op.op === "add") {
      if (!list.includes(op.unit)) list.push(op.unit);
    } else if (op.op === "remove") {
      list = list.filter((entry) => entry !== op.unit);
    } else {
      const at = list.indexOf(op.unit);
      if (at === -1) continue;
      list.splice(at, 1);
      const before = op.before === null ? -1 : list.indexOf(op.before);
      if (before === -1) list.push(op.unit);
      else list.splice(before, 0, op.unit);
    }
  }
  return list;
}

/** A copy's definition as it will be read: the definition it was made with,
 *  the project's later edits written in, and its build list resolved. Folded
 *  because a copy owns its table outright, so there is nothing for a sparse
 *  patch to be sparse against. */
function resolvedCloneDef(clone: UnitClone, edits: GameEdits): Json {
  let def: Json = structuredClone(clone.def);
  const patch = edits.overrides.get(clone.key);
  if (patch) {
    for (const [path, value] of sorted(patch)) {
      def = writePath(def, path.split("."), structuredClone(value));
    }
  }
  const ops = edits.menus.get(clone.key);
  if (ops) {
    const key = buildOptionsEntry(def)?.[0] ?? "buildoptions";
    def = writePath(def, [key], applyBuildMenu(buildOptionsOf(def), ops));
  }
  return def;
}

function table(entries: Array<[string, string]>): string {
  return `{\n${entries.map(([key, lua]) => `  [${luaString(key)}] = ${lua},`).join("\n")}\n}`;
}

/**
 * Every unit a project adds, as assignments.
 *
 * Assignment rather than a merge because there is nothing to merge onto: the
 * game has no unit of this name, which is the whole point of a copy, and
 * BAR's `tweakunits` merge would find no key and drop it. One table and one
 * loop rather than a line per unit, so a project adding sixty units does not
 * write `UnitDefs[...] =` sixty times into a slot with a size cap on it.
 */
function addedBlock(entries: Array<[string, string]>): string {
  const body = entries.map(([key, lua]) => `    [${luaString(key)}] = ${lua},`).join("\n");
  return [
    "-- Units added. Assigned directly: none of these existed before, so there is",
    "-- nothing to merge onto.",
    "do",
    `  local added = {\n${body}\n  }`,
    "  for name, def in pairs(added) do",
    "    UnitDefs[name] = def",
    "  end",
    "end",
  ].join("\n");
}

/** A whole definition standing in for one the game already loaded. */
function replaceBlock(clone: UnitClone, def: Json): string {
  const from = clone.source === null ? "" : ` Copied from ${commentText(clone.source)}.`;
  const key = luaString(clone.key);
  return `-- Replaces the game's own ${key}.${from}\ndo\n  UnitDefs[${key}] = ${luaLiteral(def, "  ")}\nend`;
}

/**
 * One builder's menu, replayed over whatever the game ships, so a unit the
 * game adds to that builder later is still there.
 *
 * Written to stand on its own, because the packer may put each block in a
 * slot of its own and a block that leaned on a helper from another slot would
 * break the moment they were ordered differently.
 */
function menuBlock(builder: string, ops: BuildMenuOp[]): string {
  const lines = [
    `-- Build menu for ${commentText(builder)}.`,
    "do",
    `  local def = UnitDefs[${luaString(builder)}]`,
    "  if def then",
    '    local key = "buildoptions"',
    "    if def[key] == nil then",
    "      for k in pairs(def) do",
    '        if string.lower(k) == "buildoptions" then',
    "          key = k",
    "          break",
    "        end",
    "      end",
    "    end",
    "    local list = {}",
    '    if type(def[key]) == "table" then',
    "      local keys = {}",
    "      for i in pairs(def[key]) do",
    '        if type(i) == "number" then',
    "          keys[#keys + 1] = i",
    "        end",
    "      end",
    "      table.sort(keys)",
    "      for _, i in ipairs(keys) do",
    "        list[#list + 1] = def[key][i]",
    "      end",
    "    end",
    "    local function at(unit)",
    "      for i = 1, #list do",
    "        if string.lower(tostring(list[i])) == unit then",
    "          return i",
    "        end",
    "      end",
    "    end",
  ];
  for (const op of ops) {
    const unit = luaString(op.unit);
    if (op.op === "add") {
      lines.push(`    if not at(${unit}) then`, `      list[#list + 1] = ${unit}`, "    end");
    } else if (op.op === "remove") {
      lines.push(
        "    do",
        `      local i = at(${unit})`,
        "      if i then",
        "        table.remove(list, i)",
        "      end",
        "    end",
      );
    } else {
      lines.push(
        "    do",
        `      local i = at(${unit})`,
        "      if i then",
        "        local moved = table.remove(list, i)",
      );
      if (op.before === null) {
        lines.push("        list[#list + 1] = moved");
      } else {
        lines.push(
          `        local before = at(${luaString(op.before)})`,
          "        if before then",
          "          table.insert(list, before, moved)",
          "        else",
          "          list[#list + 1] = moved",
          "        end",
        );
      }
      lines.push("      end", "    end");
    }
  }
  lines.push("    def[key] = list", "  end", "end");
  return lines.join("\n");
}

/** Every unit the project switches off, taken out of every build list. */
function disabledBlock(disabled: string[]): string {
  return [
    "-- Units switched off, taken out of every build menu in the game.",
    "do",
    "  local off = {",
    ...disabled.map((unit) => `    [${luaString(unit)}] = true,`),
    "  }",
    "  for _, def in pairs(UnitDefs) do",
    "    for key, list in pairs(def) do",
    '      if string.lower(key) == "buildoptions" and type(list) == "table" then',
    "        local keys = {}",
    "        for i in pairs(list) do",
    '          if type(i) == "number" then',
    "            keys[#keys + 1] = i",
    "          end",
    "        end",
    "        table.sort(keys)",
    "        local kept = {}",
    "        for _, i in ipairs(keys) do",
    "          if not off[string.lower(tostring(list[i]))] then",
    "            kept[#kept + 1] = list[i]",
    "          end",
    "        end",
    "        def[key] = kept",
    "      end",
    "    end",
    "  end",
    "end",
  ].join("\n");
}

/** Compile a project to its chunks. Throws {@link TooLarge} for the one input
 *  that would cost more to compile than any slot could carry. */
export function compile(project: ModProject): CompiledProject {
  const { edits } = project;
  const chunks: Chunk[] = [];
  const clones = sorted(edits.clones).map(([, clone]) => clone);

  const leftOut = clones.filter((clone) => !validUnitKey(clone.key)).map((clone) => clone.key);
  const usable = clones.filter((clone) => validUnitKey(clone.key));

  // Lua the project carries but cannot edit, compiled first so everything
  // the author did in coilbox lands on top of it. The import is the baseline
  // they started from: a program that scales every unit's health has to run
  // before the one unit they then typed a number into.
  for (const block of project.readOnlyLua) {
    if (block.form !== "block") continue;
    chunks.push({
      form: "block",
      title: block.title,
      reason:
        "Carried from a decoded import as it stands. Coilbox never runs it, and cannot edit it either, so it is written out the way it arrived.",
      lua: block.lua,
    });
  }

  // A copy under a name nothing else uses. Assigned rather than left as a
  // plain table: BAR's tweakunits route walks the units the game already has
  // and merges a tweak into each one it finds a key for, so a key naming a
  // unit the game does not have matches nothing and the added unit is
  // dropped with no error. Only an assignment creates one.
  const added = usable.filter((clone) => !clone.replacesGameUnit);
  if (added.length > 0) {
    chunks.push({
      form: "block",
      title: `${added.length} unit${plural(added.length)} added`,
      reason:
        "A copy owns its whole definition, and the game has no unit of that name to merge onto, so it has to be assigned rather than merged.",
      lua: addedBlock(
        added.map((clone) => [clone.key, luaLiteral(resolvedCloneDef(clone, edits), "    ")]),
      ),
    });
  }

  // A copy under a name the game already uses. It has to land after the
  // game's own definitions and replace them, so the table form cannot carry it.
  for (const clone of usable.filter((c) => c.replacesGameUnit)) {
    chunks.push({
      form: "block",
      title: `${clone.key} replaced`,
      reason: `The game defines ${clone.key} too, so this has to be written after its definitions have loaded, and it replaces rather than merges.`,
      lua: replaceBlock(clone, resolvedCloneDef(clone, edits)),
    });
  }

  // Field changes against the game's own units. A patch and nothing more:
  // every field the project does not mention keeps following the game.
  const patches: Array<[string, string]> = [];
  let fields = 0;
  for (const [unit, patch] of sorted(edits.overrides)) {
    if (edits.clones.has(unit)) continue; // Folded into the copy's own definition.
    fields += patch.size;
    const tree = new PatchTree();
    for (const [path, value] of sorted(patch)) tree.insert(path, value);
    if (!tree.isEmpty) patches.push([unit, tree.toLua("  ")]);
  }
  if (patches.length > 0) {
    chunks.push({
      form: "table",
      title: `${fields} field change${plural(fields)}`,
      reason:
        "Each one is a value the user typed, so none of them has to read the game's own first.",
      lua: table(patches),
    });
  }

  for (const [builder, ops] of sorted(edits.menus)) {
    if (edits.clones.has(builder) || ops.length === 0) continue;
    chunks.push({
      form: "block",
      title: `${builder} build menu`,
      reason:
        "Replayed over the list the game ships, so a unit it adds to this builder later is still there.",
      lua: menuBlock(builder, ops),
    });
  }

  // Last, so it reaches every list the blocks above left behind.
  if (edits.disabled.length > 0) {
    chunks.push({
      form: "block",
      title: `${edits.disabled.length} unit${plural(edits.disabled.length)} switched off`,
      reason: "It reads every builder in the game before it writes to any of them.",
      lua: disabledBlock(edits.disabled),
    });
  }

  return { chunks, leftOut };
}
