/**
 * Packing compiled chunks across Beyond All Reason's numbered tweak slots.
 *
 * A port of coilbox's `crates/tauri-plugin-coilbox-workshop/src/bar_pack.rs`,
 * held to it by `barPack.test.ts`. Every line carries the `!bset <slot> `
 * prefix a SPADS based autohost answers to, because the length that matters
 * is the whole chat line the server reads and not only the payload inside it.
 *
 * A table cannot be joined onto another, so every table chunk gets a
 * `tweakunits` slot of its own. A block is a self contained statement, so as
 * many as fit are joined into one `tweakdefs` slot before the next is started,
 * in the order the compiler wrote them, which is the order they have to run.
 */

import type { Chunk } from "./compile";

/** The base64 payload cap, per slot. Coilbox's own figure (its issue #1277),
 *  385 characters under where teiserver starts truncating a line. */
export const PAYLOAD_CAP = 16_000;

/** The whole `!bset` line, past which teiserver silently drops characters. */
const LINE_CAP = 16_385;

/** The bare option plus numbered 1 to 29, which is what BAR declares. */
const MAX_SLOTS = 30;

export interface BarSlotPack {
  /** One `!bset tweakdefs...` line per filled slot. */
  tweakdefs: string[];
  /** One `!bset tweakunits...` line per filled slot. Always one chunk each. */
  tweakunits: string[];
  /** Titles of chunks too long for a slot even alone. */
  oversized: string[];
  /** Titles of chunks that fit a slot, when every slot was already taken. */
  unplaced: string[];
}

/**
 * The level of a long bracket opening at `start`, counting the `=` signs
 * between its two `[`. `0` for `[[`, `2` for `[==[`, and null for an
 * ordinary `[`, which is how an index is told from a string.
 */
function longBracketLevel(chars: string[], start: number): number | null {
  if (chars[start] !== "[") return null;
  let level = 0;
  while (chars[start + 1 + level] === "=") level += 1;
  return chars[start + 1 + level] === "[" ? level : null;
}

/** Where the long bracket opened at `level` closes, as the index one past
 *  its final `]`, or null when it never does. */
function longBracketClose(chars: string[], from: number, level: number): number | null {
  for (let i = from; i < chars.length; i += 1) {
    if (chars[i] !== "]") continue;
    let matched = true;
    for (let n = 0; n < level; n += 1) if (chars[i + 1 + n] !== "=") matched = false;
    if (matched && chars[i + 1 + level] === "]") return i + level + 2;
  }
  return null;
}

/**
 * Strip comments and collapse whitespace to single spaces, without touching a
 * string literal. A removed run becomes one space and never nothing, or `end`
 * and `if` on their own lines would merge into one identifier.
 *
 * All three of Lua's string forms are tracked, not just `"`. The compiler
 * writes no other kind, but a project can carry Lua somebody else wrote, and
 * the tools BAR players use quote with `'` throughout. Missing that would let
 * a `--` inside a single-quoted string read as the start of a comment and
 * swallow the rest of the line, turning working Lua into a syntax error in
 * the exported slot, where nothing would notice until a game failed to start.
 */
export function minifyLua(source: string): string {
  const chars = [...source];
  let out = "";
  /** The quote character of a short string, the level of a long one, or null. */
  let short: string | null = null;
  let long: number | null = null;
  let pendingSpace = false;

  for (let i = 0; i < chars.length; i += 1) {
    const c = chars[i];
    if (short !== null) {
      out += c;
      if (c === "\\" && i + 1 < chars.length) out += chars[(i += 1)];
      else if (c === short) short = null;
      continue;
    }
    if (long !== null) {
      const end = longBracketClose(chars, i, long);
      if (end === null) {
        out += c;
      } else {
        out += chars.slice(i, end).join("");
        i = end - 1;
        long = null;
      }
      continue;
    }
    if (c === '"' || c === "'") {
      if (pendingSpace) out += " ";
      pendingSpace = false;
      short = c;
      out += c;
      continue;
    }
    if (c === "-" && chars[i + 1] === "-") {
      // A long comment runs to its matching bracket, across as many lines as
      // it likes. A plain one ends at the first newline.
      const level = longBracketLevel(chars, i + 2);
      if (level !== null) {
        i = (longBracketClose(chars, i + 2 + level + 2, level) ?? chars.length) - 1;
      } else {
        while (i < chars.length && chars[i] !== "\n") i += 1;
      }
      pendingSpace = out !== "";
      continue;
    }
    const level = longBracketLevel(chars, i);
    if (level !== null) {
      if (pendingSpace) out += " ";
      pendingSpace = false;
      const body = i + level + 2;
      out += chars.slice(i, body).join("");
      i = body - 1;
      long = level;
      continue;
    }
    if (/\s/.test(c)) {
      pendingSpace = out !== "";
      continue;
    }
    if (pendingSpace) out += " ";
    pendingSpace = false;
    out += c;
  }
  return out;
}

/** UTF-8 text to unpadded standard base64, which is what `btoa` already
 *  spells once its padding is dropped. */
function base64(text: string): string {
  let binary = "";
  for (const byte of new TextEncoder().encode(text)) binary += String.fromCharCode(byte);
  return btoa(binary).replaceAll("=", "");
}

/** The alphabet a `tweakdefs` slot carries. BAR hands that slot straight to
 *  its own decoder, which reads this. */
function encode(text: string): string {
  return base64(text).replaceAll("+", "-").replaceAll("/", "_");
}

/**
 * The same bytes for a `tweakunits` slot, which BAR reads through one step
 * more and that step destroys the URL-safe alphabet.
 *
 * `CustomKeyToUsefulTable` runs `string.gsub(dataRaw, "_", "=")` before it
 * decodes, so every `_` becomes padding, which its table maps to nil, and
 * the byte is dropped. The standard alphabet goes through untouched, because
 * it spells 62 and 63 as `+` and `/`, and BAR's decoder reads both: its
 * table holds `['+'] = 62, ['/'] = 63` beside the URL-safe pair. Padding
 * stays off, since that decoder walks its input four characters at a time
 * and a short final group simply yields fewer bytes.
 */
function encodeTweakunits(text: string): string {
  return base64(text);
}

function prefix(kind: "tweakdefs" | "tweakunits", slot: number): string {
  return slot === 0 ? `!bset ${kind} ` : `!bset ${kind}${slot} `;
}

/** Measured on the whole line as well as the payload. BAR's own tool checks
 *  the payload alone, and hands teiserver lines it then truncates. */
function fits(linePrefix: string, payload: string): boolean {
  return payload.length <= PAYLOAD_CAP && linePrefix.length + payload.length <= LINE_CAP;
}

/** Pack every chunk the compiler produced across BAR's slots. */
export function packBarSlots(chunks: Chunk[]): BarSlotPack {
  const pack: BarSlotPack = { tweakdefs: [], tweakunits: [], oversized: [], unplaced: [] };

  for (const chunk of chunks.filter((c) => c.form === "table")) {
    const slot = pack.tweakunits.length;
    if (slot >= MAX_SLOTS) {
      pack.unplaced.push(chunk.title);
      continue;
    }
    const payload = encodeTweakunits(minifyLua(chunk.lua));
    if (fits(prefix("tweakunits", slot), payload)) {
      pack.tweakunits.push(`${prefix("tweakunits", slot)}${payload}`);
    } else {
      pack.oversized.push(chunk.title);
    }
  }

  let current = "";
  const seal = () => {
    pack.tweakdefs.push(`${prefix("tweakdefs", pack.tweakdefs.length)}${encode(current)}`);
    current = "";
  };
  for (const chunk of chunks.filter((c) => c.form === "block")) {
    if (current === "" && pack.tweakdefs.length >= MAX_SLOTS) {
      pack.unplaced.push(chunk.title);
      continue;
    }
    const minified = minifyLua(chunk.lua);
    const candidate = current === "" ? minified : `${current} ${minified}`;
    if (fits(prefix("tweakdefs", pack.tweakdefs.length), encode(candidate))) {
      current = candidate;
      continue;
    }

    // Did not fit beside what this slot already holds. Seal it, if it holds
    // anything, and try this chunk alone in the next one.
    if (current !== "") seal();
    if (pack.tweakdefs.length >= MAX_SLOTS) {
      pack.unplaced.push(chunk.title);
    } else if (fits(prefix("tweakdefs", pack.tweakdefs.length), encode(minified))) {
      current = minified;
    } else {
      pack.oversized.push(chunk.title);
    }
  }
  if (current !== "") seal();

  return pack;
}
