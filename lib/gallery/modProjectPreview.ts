/**
 * Pure logic behind the mod-project preview in `components/ItemPreview.tsx`
 * and the card's counts (`components/ItemCard.tsx`), split out the way
 * `presetPreview.ts` and `blueprintPreview.ts` are, so it can be unit tested
 * without a rendering library.
 *
 * A project has no picture (issue #324). What it has is a set of edits
 * against one game, carried in full in the payload's `edits`: `overrides`
 * (fields changed on units the game already has), `clones` (new units copied
 * from old ones), `menus` (build menu operations), `text` (renamed or
 * redescribed units) and `disabled` (units switched off). `src/workshop/
 * project.ts` in coilbox is the shape - `GameEdits` for the five stores and
 * `EditCounts` for the numbers the app itself counts on a project's own card.
 * That module is not vendored here: the hub reads a project the way it reads
 * a preset or a blueprint, off an untrusted published payload rather than off
 * the app's own typed store, so every shape below is read defensively rather
 * than imported.
 *
 * `ModProjectCounts` carries one number coilbox's own `EditCounts` does not:
 * `unitsTouched`, how many distinct units carry an override or a text edit.
 * `fields` already totals across every one of them, and a reader comparing
 * "40 fields changed" against "8 units" reads a rebalance differently than
 * against "40 units".
 */

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : undefined;
}

/** A unit key, normalised the way every one of the five stores keys itself in
 *  coilbox: trimmed and lower cased. Malformed keys read as nothing rather
 *  than throwing, the same tolerance `blueprintPreview.ts` gives a bad def. */
function unitKey(raw: string): string | null {
  const key = raw.trim().toLowerCase();
  return key === "" ? null : key;
}

/** `overrides`: a unit key to a table of fields, sparse at both levels. Reads
 *  as a field count per unit, which is all the counts and the changelog need. */
function parseFieldCounts(value: unknown): Map<string, number> {
  const source = asRecord(value);
  const out = new Map<string, number>();
  if (!source) return out;
  for (const [raw, fields] of Object.entries(source)) {
    const key = unitKey(raw);
    const record = asRecord(fields);
    if (key && record) {
      const n = Object.keys(record).length;
      if (n > 0) out.set(key, n);
    }
  }
  return out;
}

/** `text`: a unit key to a table of languages, each a table of fields. A
 *  unit's count sums every language it holds an edit in, the way coilbox's
 *  own `unitTextCount` does. */
function parseTextCounts(value: unknown): Map<string, number> {
  const source = asRecord(value);
  const out = new Map<string, number>();
  if (!source) return out;
  for (const [raw, languages] of Object.entries(source)) {
    const key = unitKey(raw);
    const record = asRecord(languages);
    if (!key || !record) continue;
    let count = 0;
    for (const fields of Object.values(record)) {
      const entry = asRecord(fields);
      if (entry) count += Object.keys(entry).length;
    }
    if (count > 0) out.set(key, count);
  }
  return out;
}

/** `clones`: a unit key to a whole definition. Only the source it names is
 *  worth carrying here - the definition itself is not read for a count or a
 *  changelog line. */
function parseClones(value: unknown): Map<string, string | undefined> {
  const source = asRecord(value);
  const out = new Map<string, string | undefined>();
  if (!source) return out;
  for (const [raw, entry] of Object.entries(source)) {
    const key = unitKey(raw);
    const clone = asRecord(entry);
    if (!key || !clone) continue;
    out.set(key, typeof clone.source === "string" ? clone.source : undefined);
  }
  return out;
}

/** `menus`: a builder's own unit key to its list of build menu operations. */
function parseMenuCounts(value: unknown): Map<string, number> {
  const source = asRecord(value);
  const out = new Map<string, number>();
  if (!source) return out;
  for (const [raw, ops] of Object.entries(source)) {
    const key = unitKey(raw);
    if (key && Array.isArray(ops) && ops.length > 0) out.set(key, ops.length);
  }
  return out;
}

/** `disabled`: a plain list of unit keys, however untrustworthy the source. */
function parseDisabled(value: unknown): Set<string> {
  const out = new Set<string>();
  if (!Array.isArray(value)) return out;
  for (const entry of value) {
    if (typeof entry !== "string") continue;
    const key = unitKey(entry);
    if (key) out.add(key);
  }
  return out;
}

/** The five stores, already reduced to what the counts and the changelog
 *  need: no field values, no clone definitions, nothing that would cost
 *  bytes without adding a number or a name to draw. */
export interface ParsedProjectEdits {
  overrides: Map<string, number>;
  text: Map<string, number>;
  clones: Map<string, string | undefined>;
  menus: Map<string, number>;
  disabled: Set<string>;
}

/** Read the five stores out of an untrusted `edits` value - the shape at
 *  `payload.edits` on the item page, and the shape PostgREST hands back for
 *  the card's own batched lookup (`cardCounts.ts`), which asks for exactly
 *  these five paths and nothing else in the payload. */
export function parseProjectEdits(value: unknown): ParsedProjectEdits {
  const source = asRecord(value);
  return {
    overrides: parseFieldCounts(source?.overrides),
    text: parseTextCounts(source?.text),
    clones: parseClones(source?.clones),
    menus: parseMenuCounts(source?.menus),
    disabled: parseDisabled(source?.disabled),
  };
}

/** How much a project changes, for the card and the top of the item page. */
export interface ModProjectCounts {
  /** Distinct units with a field or a text edit against them - the "how many
   *  units" a `fields` total does not say on its own. */
  unitsTouched: number;
  /** Fields changed, overrides and text edits together, the same total
   *  coilbox's own `editCounts` reports. */
  fields: number;
  /** Units the project adds. */
  clones: number;
  /** Build menu operations, across every builder. */
  menuOps: number;
  /** Units switched off. */
  disabled: number;
}

function sum(values: Iterable<number>): number {
  let n = 0;
  for (const v of values) n += v;
  return n;
}

/** The counts from the five stores directly, for a caller that already has
 *  them - the changelog below, and the card's batched lookup, which parses a
 *  page's worth of rows once rather than once per card. */
export function editCountsFromParsed(edits: ParsedProjectEdits): ModProjectCounts {
  return {
    unitsTouched: new Set([...edits.overrides.keys(), ...edits.text.keys()]).size,
    fields: sum(edits.overrides.values()) + sum(edits.text.values()),
    clones: edits.clones.size,
    menuOps: sum(edits.menus.values()),
    disabled: edits.disabled.size,
  };
}

/** The counts a project's card and page open with, read straight off a
 *  container payload's `edits`. */
export function modProjectCounts(payload: unknown): ModProjectCounts {
  return editCountsFromParsed(parseProjectEdits(asRecord(payload)?.edits));
}

/** One unit's row in the changelog: what changed on it, whichever of the five
 *  stores said so. */
export interface ProjectUnitChange {
  /** The game's own internal name for the unit, lower case. */
  unit: string;
  fields: number;
  textFields: number;
  added: boolean;
  /** The unit this one was copied from, when the payload named one. */
  source?: string;
  disabled: boolean;
  /** Build menu operations against this unit's own menu, when it is a
   *  builder the project changed. */
  menuOps: number;
}

/** Every unit the project's changelog has something to say about, straight
 *  off the five parsed stores, in alphabetical order - the order a reader
 *  scans a list of names in, not the order any one store happened to hold
 *  them. */
export function changelogFromParsed(edits: ParsedProjectEdits): ProjectUnitChange[] {
  const units = new Set([
    ...edits.overrides.keys(),
    ...edits.text.keys(),
    ...edits.clones.keys(),
    ...edits.menus.keys(),
    ...edits.disabled,
  ]);

  return [...units]
    .sort((a, b) => a.localeCompare(b))
    .map((unit) => ({
      unit,
      fields: edits.overrides.get(unit) ?? 0,
      textFields: edits.text.get(unit) ?? 0,
      added: edits.clones.has(unit),
      source: edits.clones.get(unit),
      disabled: edits.disabled.has(unit),
      menuOps: edits.menus.get(unit) ?? 0,
    }));
}

/** The item page's changelog, read straight off a container payload's
 *  `edits`. */
export function modProjectChangelog(payload: unknown): ProjectUnitChange[] {
  return changelogFromParsed(parseProjectEdits(asRecord(payload)?.edits));
}

/** One row's change, in words: "3 fields changed, disabled". Mirrors
 *  coilbox's own `describeEdits` sentence, one unit at a time rather than for
 *  the whole project. */
export function describeUnitChange(change: ProjectUnitChange): string {
  const parts = [
    change.fields > 0 &&
      `${change.fields} field${change.fields === 1 ? "" : "s"} changed`,
    change.textFields > 0 &&
      `${change.textFields} text field${change.textFields === 1 ? "" : "s"} changed`,
    change.added && (change.source ? `copied from ${change.source}` : "added"),
    change.menuOps > 0 &&
      `${change.menuOps} build menu edit${change.menuOps === 1 ? "" : "s"}`,
    change.disabled && "disabled",
  ].filter((part): part is string => typeof part === "string");
  return parts.length === 0 ? "changed" : parts.join(", ");
}

/**
 * How many rows the item page's changelog draws before folding the rest into
 * a count.
 *
 * A full-roster rebalance can touch every unit in a game - Beyond All Reason
 * ships 564 in a single build - and a page that renders every one of them is
 * a page nobody can read or scroll to the end of. 200 keeps the list to
 * something a reader can actually scan in one sitting, while still showing
 * more units than any project short of a full-roster rebalance touches. The
 * remainder is folded into a single line saying how many more there are,
 * rather than silently dropped, so the true scope of the project is never
 * hidden.
 */
export const CHANGELOG_ROW_LIMIT = 200;
