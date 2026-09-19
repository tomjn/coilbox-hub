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

import { type BarSlotPack, packBarSlots } from "./barPack";
import { type Chunk, compile, TooLarge } from "./compile";
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

function missing(pack: BarSlotPack): string[] {
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

  const pack = packBarSlots(compiled.chunks);
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
    lines: withheld.length > 0 ? [] : [...pack.tweakunits, ...pack.tweakdefs],
    withheld,
    notCarried,
    chunks: compiled.chunks,
  };
}
