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
 * Strip comments and collapse whitespace to single spaces, without touching a
 * string literal. Only `"` is tracked because the compiler writes no other
 * kind of string. A removed run becomes one space and never nothing, or `end`
 * and `if` on their own lines would merge into one identifier.
 */
export function minifyLua(source: string): string {
  const chars = [...source];
  let out = "";
  let inString = false;
  let pendingSpace = false;

  for (let i = 0; i < chars.length; i += 1) {
    const c = chars[i];
    if (inString) {
      out += c;
      if (c === "\\" && i + 1 < chars.length) out += chars[(i += 1)];
      else if (c === '"') inString = false;
      continue;
    }
    if (c === "-" && chars[i + 1] === "-") {
      while (i < chars.length && chars[i] !== "\n") i += 1;
      pendingSpace = out !== "";
      continue;
    }
    if (/\s/.test(c)) {
      pendingSpace = out !== "";
      continue;
    }
    if (pendingSpace) out += " ";
    pendingSpace = false;
    if (c === '"') inString = true;
    out += c;
  }
  return out;
}

/** UTF-8 text to URL-safe base64, padding stripped. */
function encode(text: string): string {
  let binary = "";
  for (const byte of new TextEncoder().encode(text)) binary += String.fromCharCode(byte);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replaceAll("=", "");
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
    const payload = encode(minifyLua(chunk.lua));
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
