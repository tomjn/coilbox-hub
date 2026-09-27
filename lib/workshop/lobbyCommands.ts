/**
 * What a published project's page offers somebody who does not have coilbox:
 * the `!bset` lines to paste into a lobby, and the Lua behind them (issue
 * #418).
 *
 * Whether a particular game reads tweak slots is not decided here. That is a
 * fact about the game, and coilbox is what knows it. This module answers only
 * for the project: whether it can be read, and whether all of it fits.
 *
 * Lines are withheld whole when any part of the project would be missing from
 * them. Coilbox offers its own user the rest and says what was left out, but
 * they made the project and can judge that. A visitor pasting lines from a web
 * page cannot, and a build menu naming a unit that never arrived is a worse
 * first experience than being told to use the app.
 */

import { packTweakSlots, type TweakSlotPack } from "./barPack";
import { type Chunk, compile, TooLarge } from "./compile";
import type { Json } from "./lua";
import type { ModProject } from "./project";
import { readModProject } from "./project";

export interface LobbyCommands {
  /** Which item the lines came from. A preset's read the same way but are
   *  ordered, carry no Lua, and are described differently on the page. */
  kind: "project" | "preset";
  /** Every line, in the order they are meant to be sent. A project's set one
   *  option each, so its order is only for reading. A preset's has to be kept,
   *  because the map is what the lines after it resolve against. */
  lines: string[];
  /** Why there are no lines, in words for the page. Empty when there are. */
  withheld: string[];
  /** What the lines cannot carry even when they are complete. */
  notCarried: string[];
  /** The readable Lua, for somebody who wants to see what they are pasting. */
  chunks: Chunk[];
  /** Said beside the lines when a field change writes a number: a game's own
   *  Lua can turn a typed number into a different one as it loads, and only
   *  the app can load the game to check and write the number it turns into
   *  instead (issue #3121). `null` when nothing in the lines could be one of
   *  those, or the item is a preset, which sets options as text rather than
   *  as a typed field. */
  typedValuesNote: string | null;
}

const TYPED_VALUES_NOTE =
  "These lines write each value as typed. The game may load some of them as something else.";

/** Whether a value, or anything nested inside it, is a number. A field's own
 *  path is a flat dotted string in every override coilbox's own editors
 *  write, but the value at that path is JSON and nothing here refuses a
 *  compound one: an import can carry one, and `compile`'s `writePath` writes
 *  it out exactly as given, nested numbers and all (issue #3121). */
function hasTypedNumber(value: Json): boolean {
  if (typeof value === "number") return true;
  if (Array.isArray(value)) return value.some(hasTypedNumber);
  if (typeof value === "object" && value !== null) {
    return Object.values(value).some(hasTypedNumber);
  }
  return false;
}

/** Whether a field change in the lines could be one of the numbers a game's
 *  own Lua rewrites while it loads. A copy's own fields are excluded: they
 *  are written as a whole definition rather than a change against the game's,
 *  and no route settles them either (issue #3121). */
function hasTypedFieldChanges(project: ModProject): boolean {
  for (const [unit, patch] of project.edits.overrides) {
    if (project.edits.clones.has(unit)) continue;
    for (const value of patch.values()) {
      if (hasTypedNumber(value)) return true;
    }
  }
  return false;
}

function plural(n: number): string {
  return n === 1 ? "" : "s";
}

/** The copies that claim one unit name between them. Coilbox refuses to pack
 *  these: only one would reach the game. */
function contestedNames(clones: Iterable<{ key: string }>): string[] {
  const seen = new Map<string, number>();
  for (const { key } of clones) seen.set(key, (seen.get(key) ?? 0) + 1);
  return [...seen].filter(([, n]) => n > 1).map(([key]) => key);
}

function missing(pack: TweakSlotPack): string[] {
  return [
    ...pack.oversized.map((title) => `"${title}" is too long for one autohost command.`),
    ...pack.unplaced.map((title) => `"${title}" did not fit in the slots a lobby has.`),
  ];
}

/** `null` when the payload is not a project coilbox could compile, or holds no
 *  edit a slot can carry. */
export function lobbyCommands(payload: unknown): LobbyCommands | null {
  const project = readModProject(payload);
  if (!project) return null;

  let compiled;
  try {
    compiled = compile(project);
  } catch (error) {
    // A `RangeError` is the stack running out on a payload nested thousands
    // deep, which no editor writes and no slot could hold either.
    if (error instanceof TooLarge || error instanceof RangeError) return null;
    throw error;
  }
  if (compiled.chunks.length === 0) return null;

  const pack = packTweakSlots(compiled.chunks);
  const withheld = [
    ...contestedNames(project.edits.clones.values()).map(
      (key) => `More than one copy in this project is named ${key}.`,
    ),
    ...missing(pack),
  ];

  const { textEdits } = project.edits;
  const leftBehind = project.readOnlyLua.filter((block) => block.form !== "block").length;
  const notCarried = [
    textEdits > 0 &&
      `${textEdits} name or description edit${plural(textEdits)}. An autohost command cannot change a unit's words.`,
    // Only the ones left out. A carried block that parsed is compiled into
    // the lines above like any other, so listing it here would tell a host
    // something is missing when it is in their hands.
    leftBehind > 0 &&
      `${leftBehind} block${plural(leftBehind)} of imported Lua that never parsed, so it is not in these lines.`,
    compiled.leftOut.length > 0 &&
      `${compiled.leftOut.length} cop${compiled.leftOut.length === 1 ? "y" : "ies"} with a name a unit cannot have.`,
  ].filter((note): note is string => typeof note === "string");

  return {
    kind: "project",
    lines: withheld.length > 0 ? [] : pack.tweakdefs,
    withheld,
    notCarried,
    chunks: compiled.chunks,
    typedValuesNote:
      withheld.length === 0 && hasTypedFieldChanges(project) ? TYPED_VALUES_NOTE : null,
  };
}
