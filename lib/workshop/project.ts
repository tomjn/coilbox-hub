/**
 * A published project, read as strictly as coilbox's compiler reads one.
 *
 * A mirror of `crates/tauri-plugin-coilbox-workshop/src/model.rs` in coilbox.
 * `lib/gallery/modProjectPreview.ts` reads the same payload tolerantly,
 * because a changelog row that skips a malformed entry is still a useful row.
 * This reader is for the lines a host pastes into a lobby, where skipping is
 * wrong: coilbox refuses to compile a project whose shape it cannot read, so
 * the hub offers no commands for one either, instead of commands for part of
 * it. Anything serde would reject comes back as `null`.
 */

import type { Json } from "./lua";

export interface UnitClone {
  /** Its internal name, which is its key in the game's unit table. */
  key: string;
  /** The unit it was copied from. Absent for one built from parts. */
  source: string | null;
  /** Whether it stands in for a unit the game already has. */
  replacesGameUnit: boolean;
  def: Json;
}

export type BuildMenuOp =
  | { op: "add"; unit: string }
  | { op: "remove"; unit: string }
  /** Put `unit` immediately before `before`, or on the end when it is null. */
  | { op: "move"; unit: string; before: string | null };

export interface GameEdits {
  /** Unit, then dotted field path, to the value the author set. */
  overrides: Map<string, Map<string, Json>>;
  /** Keyed by an id of the project's own, not by the unit's name. */
  clones: Map<string, UnitClone>;
  menus: Map<string, BuildMenuOp[]>;
  /** How many name and description edits the project holds. Only the count,
   *  because no tweak slot can carry them. */
  textEdits: number;
  disabled: string[];
}

/**
 * A block of Lua the project carries but cannot edit, from a decoded import.
 *
 * Read only is about editing, not about compiling. Coilbox compiles a block
 * its decoder proved is a Lua chunk, because most of a real imported tweak
 * set is program rather than data and a project that dropped it would keep
 * the small editable part and lose the rest. `form` is that decoder's own
 * verdict, and only `"block"` is compiled. Anything else never parsed, and
 * writing it into a slot would break the slot rather than only itself.
 */
export interface ReadOnlyLuaBlock {
  title: string;
  lua: string;
  form: "block" | "unrecognised" | null;
}

export interface ModProject {
  name: string;
  edits: GameEdits;
  /** Lua the project carries but does not edit, in the order it runs. */
  readOnlyLua: ReadOnlyLuaBlock[];
}

type Shape = Record<string, unknown>;

class Malformed extends Error {}

function fail(): never {
  throw new Malformed();
}

function object(value: unknown): Shape {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? (value as Shape)
    : fail();
}

function text(value: unknown): string {
  return typeof value === "string" ? value : fail();
}

function optionalText(value: unknown): string | null {
  return value === undefined || value === null ? null : text(value);
}

/** A field serde gives a default when it is absent. `null` is not absent. */
function field<T>(value: unknown, read: (v: unknown) => T, otherwise: T): T {
  return value === undefined ? otherwise : read(value);
}

function mapOf<T>(value: unknown, read: (v: unknown) => T): Map<string, T> {
  return new Map(Object.entries(object(value)).map(([key, v]) => [key, read(v)]));
}

function listOf<T>(value: unknown, read: (v: unknown) => T): T[] {
  return Array.isArray(value) ? value.map(read) : fail();
}

function clone(value: unknown): UnitClone {
  const source = object(value);
  return {
    key: text(source.key),
    source: optionalText(source.source),
    replacesGameUnit: field(
      source.replacesGameUnit,
      (v) => (typeof v === "boolean" ? v : fail()),
      false,
    ),
    // Already JSON: it came out of a jsonb column.
    def: field(source.def, (v) => v as Json, null),
  };
}

function menuOp(value: unknown): BuildMenuOp {
  const source = object(value);
  const unit = text(source.unit);
  if (source.op === "add" || source.op === "remove") return { op: source.op, unit };
  if (source.op === "move") return { op: "move", unit, before: optionalText(source.before) };
  return fail();
}

function textEditCount(value: unknown): number {
  let count = 0;
  for (const languages of mapOf(value, (v) => mapOf(v, object)).values()) {
    for (const fields of languages.values()) {
      if (optionalText(fields.name) !== null) count += 1;
      if (optionalText(fields.description) !== null) count += 1;
    }
  }
  return count;
}

function edits(value: unknown): GameEdits {
  const source = object(value);
  return {
    overrides: field(
      source.overrides,
      (v) => mapOf(v, (patch) => mapOf(patch, (leaf) => leaf as Json)),
      new Map(),
    ),
    clones: field(source.clones, (v) => mapOf(v, clone), new Map()),
    menus: field(source.menus, (v) => mapOf(v, (ops) => listOf(ops, menuOp)), new Map()),
    textEdits: field(source.text, textEditCount, 0),
    disabled: field(source.disabled, (v) => listOf(v, text), []),
  };
}

/** Read a container's payload, or `null` when coilbox could not have. */
export function readModProject(payload: unknown): ModProject | null {
  try {
    const source = object(payload);
    // Read only to refuse what coilbox would refuse.
    optionalText(source.description);
    field(source.gameName, text, "");
    return {
      name: field(source.name, text, ""),
      edits: field(source.edits, edits, edits({})),
      readOnlyLua: field(
        source.readOnlyLua,
        (v) =>
          listOf(v, (block): ReadOnlyLuaBlock => {
            const b = object(block);
            // `note` is read to refuse what coilbox would refuse, and
            // dropped: it is a sentence for a reader in the app, and the
            // hub writes its own words for the one case it mentions.
            text(b.note);
            return {
              title: text(b.title),
              lua: text(b.lua),
              // Only the decoder's own two words. A payload written by hand
              // does not get to invent a third and have it read as
              // permission to put the Lua in a slot.
              form: b.form === "block" || b.form === "unrecognised" ? b.form : null,
            };
          }),
        [],
      ),
    };
  } catch (error) {
    if (error instanceof Malformed) return null;
    throw error;
  }
}
