import type { SupabaseClient } from "@supabase/supabase-js";
import type { UnitNameLabel } from "./units";

/**
 * Two different units of one game, side by side (#466).
 *
 * The page reads both at one release. A unit's state at a release is the
 * revision keyed by exactly that release, as `lib/games/changes.ts` explains, so
 * a release can hold one of the two and not the other.
 */

export type WithResolution =
  | { kind: "none" }
  | { kind: "self" }
  | { kind: "unknown"; asked: string }
  | { kind: "ambiguous"; choices: UnitNameLabel[] }
  | { kind: "found"; unit: UnitNameLabel };

/**
 * What the `with` parameter names.
 *
 * The form submits an id, but a visitor can type a display name, so an id is
 * tried first and a display name second, both ignoring case. A display name
 * several units share lists them rather than picking one. The unit being
 * compared is left out of a shared name's matches, and is `self` only when
 * nothing else matches.
 */
export function resolveWithUnit(
  asked: string | undefined,
  self: string,
  units: UnitNameLabel[],
): WithResolution {
  const text = asked?.trim().toLowerCase();
  if (!text) return { kind: "none" };

  const byId = units.find((unit) => unit.name.toLowerCase() === text);
  if (byId) return byId.name === self ? { kind: "self" } : { kind: "found", unit: byId };

  const byLabel = units.filter((unit) => unit.label.toLowerCase() === text);
  if (byLabel.length === 0) return { kind: "unknown", asked: asked!.trim() };
  const others = byLabel.filter((unit) => unit.name !== self);
  if (others.length === 0) return { kind: "self" };
  if (others.length === 1) return { kind: "found", unit: others[0] };
  return { kind: "ambiguous", choices: others };
}

export interface ReleasePick {
  /** The release to read both units at, or null when there is none. */
  release: string | null;
  /** Every release holding both units, newest first. */
  both: string[];
  /** Set when a release was asked for and one unit lacks it. */
  missing: { a: boolean; b: boolean } | null;
  /** True when the release asked for is not one the game holds. */
  unknownRelease: boolean;
}

/**
 * The release to compare at. `releases` is every release the game holds, newest
 * first, and `heldA` and `heldB` are the releases each unit has a revision for.
 * With no release asked for the newest holding both is used. A release asked for
 * is used only when both hold it.
 */
export function pickCompareRelease(
  releases: string[],
  heldA: string[],
  heldB: string[],
  asked: string | undefined,
): ReleasePick {
  const a = new Set(heldA);
  const b = new Set(heldB);
  const both = releases.filter((release) => a.has(release) && b.has(release));

  if (!asked) return { release: both[0] ?? null, both, missing: null, unknownRelease: false };
  if (!releases.includes(asked)) return { release: null, both, missing: null, unknownRelease: true };
  if (a.has(asked) && b.has(asked)) return { release: asked, both, missing: null, unknownRelease: false };
  return { release: null, both, missing: { a: !a.has(asked), b: !b.has(asked) }, unknownRelease: false };
}

/** One unit as one release stored it. */
export interface PairRevision {
  version: string;
  full_name: string | null;
  faction_key: string | null;
  stats: Record<string, unknown>;
}

/** One unit with every release that holds a revision of it. */
export interface PairUnit {
  unit_name: string;
  full_name: string | null;
  revisions: PairRevision[];
}

/**
 * The two units with all their revisions, in the order asked. Null on a failed
 * read or when either is not held by the game. Two units' revisions are a
 * handful of rows, so one embedded read is enough.
 */
export async function loadUnitPair(
  supabase: SupabaseClient,
  shortname: string,
  nameA: string,
  nameB: string,
): Promise<[PairUnit, PairUnit] | null> {
  const { data, error } = await supabase
    .from("game_unit")
    .select(
      "unit_name,full_name,game!inner(shortname)," +
        "game_unit_revision(version,full_name,faction_key,stats)",
    )
    .eq("game.shortname", shortname)
    .in("unit_name", [nameA, nameB]);
  if (error || !data) return null;

  const rows = data as unknown as (Omit<PairUnit, "revisions"> & {
    game_unit_revision: PairRevision[] | null;
  })[];
  const unit = (name: string): PairUnit | null => {
    const row = rows.find((candidate) => candidate.unit_name === name);
    return row
      ? { unit_name: row.unit_name, full_name: row.full_name, revisions: row.game_unit_revision ?? [] }
      : null;
  };
  const a = unit(nameA);
  const b = unit(nameB);
  return a && b ? [a, b] : null;
}
