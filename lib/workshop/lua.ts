/**
 * Writing Lua source out of JSON values.
 *
 * A port of coilbox's `crates/tauri-plugin-coilbox-workshop/src/lua.rs`, kept
 * to the same output rules: bare keys where Lua allows one, double quoted
 * strings, a trailing comma on every entry, a sequence written with its
 * indices, and a table of nothing but scalars kept on one line up to sixty
 * columns. `barPack.test.ts` holds it to the Rust, case by case.
 *
 * Two places it cannot be byte identical, and neither changes what the game
 * reads. A number JSON spelled `5.0` prints as `5` here and `5.0` there,
 * because JavaScript keeps no record of the decimal point. And the two
 * languages switch to exponent notation at different sizes, so `1e16` there is
 * `10000000000000000` here. Lua 5.1 holds every number as a double, so each
 * pair is the same value.
 */

export type Json =
  | null
  | boolean
  | number
  | string
  | Json[]
  | { [key: string]: Json };

const INDENT = "  ";

/** Width at which a table of nothing but scalars stays on one line. */
const INLINE_WIDTH = 60;

/** Lua's reserved words, which cannot be used as a bare table key. */
const KEYWORDS = new Set([
  "and", "break", "do", "else", "elseif", "end", "false", "for", "function",
  "if", "in", "local", "nil", "not", "or", "repeat", "return", "then", "true",
  "until", "while",
]);

/** Code point order, which is the byte order Rust's `BTreeMap` sorts UTF-8
 *  keys in. JavaScript's default sort compares UTF-16 units, and the two
 *  disagree above the basic plane. */
export function compareKeys(a: string, b: string): number {
  const left = [...a];
  const right = [...b];
  const shared = Math.min(left.length, right.length);
  for (let i = 0; i < shared; i += 1) {
    const diff = (left[i].codePointAt(0) ?? 0) - (right[i].codePointAt(0) ?? 0);
    if (diff !== 0) return diff;
  }
  return left.length - right.length;
}

/** How wide a string is, in characters rather than UTF-16 units. */
function width(text: string): number {
  return [...text].length;
}

/**
 * Quote a string as a Lua literal.
 *
 * Anything above ASCII is left alone: Lua strings are byte strings and the
 * payload is UTF-8. Control characters become three digit `\ddd` escapes, and
 * the padding is not optional: `\0` followed by `5` would otherwise read back
 * as byte 5.
 */
export function luaString(value: string): string {
  let out = '"';
  for (const ch of value) {
    const code = ch.codePointAt(0) ?? 0;
    if (ch === "\\") out += "\\\\";
    else if (ch === '"') out += '\\"';
    else if (ch === "\n") out += "\\n";
    else if (ch === "\r") out += "\\r";
    else if (ch === "\t") out += "\\t";
    else if (code < 0x20 || code === 0x7f) out += `\\${String(code).padStart(3, "0")}`;
    else out += ch;
  }
  return `${out}"`;
}

/** Whether `key` can be written as a bare table key. Not cosmetic: `repeat` is
 *  both a Lua keyword and a real field name. */
function isLuaIdentifier(key: string): boolean {
  return /^[A-Za-z_][A-Za-z0-9_]*$/.test(key) && !KEYWORDS.has(key);
}

/** Whether an object key is one Lua indexes as a number. A Lua table indexed
 *  by number reaches JSON with string keys, and `["1"]` is a different key
 *  from `[1]`. */
function integerKey(key: string): string | null {
  if (!/^-?(0|[1-9][0-9]{0,14})$/.test(key) || key === "-0") return null;
  return key;
}

function tableKey(key: string): string {
  if (isLuaIdentifier(key)) return key;
  const n = integerKey(key);
  return n === null ? `[${luaString(key)}]` : `[${n}]`;
}

/** A number as Lua source. See this module's doc comment for where this and
 *  the Rust differ. Only the `+` of `1e+21` is removed, to match. */
function luaNumber(value: number): string {
  return String(value).replace("e+", "e");
}

function isTable(value: Json): boolean {
  return typeof value === "object" && value !== null;
}

/** The shared table body, so an array, an object and a patch are laid out
 *  identically. `parts` are `key = value` strings already rendered. */
function renderTable(parts: string[], allScalars: boolean, indent: string): string {
  if (parts.length === 0) return "{}";
  const inline = `{ ${parts.join(", ")} }`;
  if (allScalars && width(inline) + width(indent) <= INLINE_WIDTH) return inline;
  const inner = `${indent}${INDENT}`;
  return `{\n${parts.map((part) => `${inner}${part},`).join("\n")}\n${indent}}`;
}

/**
 * A JSON value as a Lua literal, one table entry per line.
 *
 * An array is written with its indices, `[1] = "armsolar"`, because both
 * Balanced Annihilation and Beyond All Reason write their own sequences that
 * way. An object's keys are sorted, which is the order the Rust reads them in.
 */
export function luaLiteral(value: Json, indent: string): string {
  if (value === null) return "nil";
  if (typeof value === "boolean") return String(value);
  if (typeof value === "number") return luaNumber(value);
  if (typeof value === "string") return luaString(value);

  const inner = `${indent}${INDENT}`;
  const entries: Array<[string, Json]> = Array.isArray(value)
    ? value.map((item, i) => [`[${i + 1}]`, item])
    : Object.keys(value)
        .sort(compareKeys)
        .map((key) => [tableKey(key), value[key]]);
  return renderTable(
    entries.map(([key, item]) => `${key} = ${luaLiteral(item, inner)}`),
    entries.every(([, item]) => !isTable(item)),
    indent,
  );
}

type PatchNode = { leaf: Json } | { branch: PatchTree };

/**
 * A sparse patch, as a tree rather than as a JSON value.
 *
 * JSON cannot express "the fourth element of this array and nothing else". A
 * patch against `weapons.3.name` has to come out as
 * `weapons = { [4] = { name = ... } }`, with no mention of the three weapons
 * it does not touch, or the merge that applies it would blank them.
 */
export class PatchTree {
  private readonly indices = new Map<number, PatchNode>();
  private readonly names = new Map<string, PatchNode>();

  get isEmpty(): boolean {
    return this.indices.size === 0 && this.names.size === 0;
  }

  /** A step of nothing but digits is an array index counted from zero, written
   *  out one higher because Lua counts from one. */
  private slot(step: string): { get(): PatchNode | undefined; set(node: PatchNode): void } {
    if (/^[0-9]+$/.test(step) && Number.isSafeInteger(Number(step))) {
      const index = Number(step) + 1;
      return {
        get: () => this.indices.get(index),
        set: (node) => void this.indices.set(index, node),
      };
    }
    return {
      get: () => this.names.get(step),
      set: (node) => void this.names.set(step, node),
    };
  }

  /** Write one dotted path into the tree. A path that runs through a value
   *  already set replaces it, because the last thing the user said about a
   *  field is what they meant. */
  insert(path: string, value: Json): void {
    const [step, ...rest] = path.split(".");
    const slot = this.slot(step);
    if (rest.length === 0) {
      slot.set({ leaf: value });
      return;
    }
    let entry = slot.get();
    if (!entry || !("branch" in entry)) {
      entry = { branch: new PatchTree() };
      slot.set(entry);
    }
    entry.branch.insert(rest.join("."), value);
  }

  /** Indices first and in numeric order, then names in key order, so the same
   *  project compiles to the same text every time. */
  toLua(indent: string): string {
    const inner = `${indent}${INDENT}`;
    const entries: Array<[string, PatchNode]> = [
      ...[...this.indices]
        .sort(([a], [b]) => a - b)
        .map(([n, node]): [string, PatchNode] => [`[${n}]`, node]),
      ...[...this.names]
        .sort(([a], [b]) => compareKeys(a, b))
        .map(([name, node]): [string, PatchNode] => [tableKey(name), node]),
    ];
    return renderTable(
      entries.map(
        ([key, node]) =>
          `${key} = ${"leaf" in node ? luaLiteral(node.leaf, inner) : node.branch.toLua(inner)}`,
      ),
      entries.every(([, node]) => "leaf" in node && !isTable(node.leaf)),
      indent,
    );
  }
}
