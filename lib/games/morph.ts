/**
 * What a unit turns into, grouped (#295).
 *
 * A commander that upgrades through tech levels is one unit at five stages of
 * its life, and the catalog holds five unrelated rows. This turns the morph
 * edges those rows carry into groups, so the grid can show one cell, the build
 * tree one node, and a unit's page the levels together.
 *
 * A pure walk over rows, the way `buildTree` is and for the same reason: three
 * readers ask the same question and one answer should not be three algorithms.
 * Keys are lowercased throughout, matching how morph targets and build options
 * arrive, so lookups are case-insensitive by construction rather than by
 * everybody remembering to normalise at every use.
 *
 * ## The graph is not a chain
 *
 * Morph edges branch, they converge, and a game can define a cycle. Every rule
 * here is written to hold on that rather than on a tidy line of levels:
 *
 * - A group is a connected component, direction ignored. Two units that morph
 *   into one upgrade are one group, because the upgrade cannot be two cells.
 * - The base is a member nothing morphs into. Several, or none because the
 *   component is a cycle, and the first by code unit wins. Sorted by code unit
 *   rather than `localeCompare` for the reason `unitDigest` spells out: the
 *   locale a process runs in must not decide what a page shows.
 * - Stages run breadth first from the base, siblings alphabetical, and any
 *   member the base cannot reach is appended. A second root of a converging
 *   group lands there, reached from nothing, which is the fact.
 *
 * An edge naming a unit the game does not hold is dropped here rather than
 * refused by the table, so a typo in one extraction does not lose the fact that
 * an edge was reported.
 */

/** One edge out of a stage: what it turns into, and what the game says it takes. */
export interface MorphEdge {
  /** The unit turned into, lowercased, which is what a stage is keyed on. */
  into: string;
  /** The game's own condition vocabulary, whatever it spells it: everything on
   *  the stored edge beside `into`. Four games spell it four ways, so it is
   *  carried rather than named. */
  conditions: Record<string, unknown>;
}

/** One stage of a group's life. */
export interface MorphStage {
  /** The def key, lowercased, which is what edges and build options name. */
  name: string;
  /** The name as the catalog stored it. A URL segment and a `unit_name` filter
   *  both need the stored spelling, not the key. */
  unit_name: string;
  /** The stage this one is reached from, lowercased. Null for the base, and
   *  for a second root that nothing in the group morphs into. */
  from: string | null;
  /** How you get here: the conditions on the edge from {@link MorphStage.from}.
   *  Empty when there is no such edge. */
  conditions: Record<string, unknown>;
  /** What this stage turns into, sorted by target. */
  into: MorphEdge[];
}

/** One unit at every stage of its life. */
export interface MorphGroup {
  /** The stage a grid cell and a tree node stand for. */
  base: string;
  /** Every member, the base first. */
  stages: MorphStage[];
}

/** A unit as the readers hand it over. `morph_targets` is `jsonb`, so it is
 *  whatever was stored until this has read it. */
export interface MorphUnit {
  unit_name: string;
  morph_targets: unknown;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/** Sorted by code unit, never by locale: a page must not read differently on a
 *  developer's machine and on a server. */
function byCodeUnit(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * The edges one unit declares, keyed and cleaned.
 *
 * Anything that is not an object naming a unit as a string is dropped. The
 * parser refuses those on the way in (`lib/api/gameFacts.ts`), so a row holding
 * one arrived before the parser knew the field or was written by hand, and a
 * reader that threw on it would take down the page rather than the edge.
 */
function edgesOf(unit: MorphUnit, held: ReadonlySet<string>): MorphEdge[] {
  if (!Array.isArray(unit.morph_targets)) return [];

  const self = unit.unit_name.toLowerCase();
  const seen = new Set<string>();
  const edges: MorphEdge[] = [];
  for (const entry of unit.morph_targets) {
    if (!isRecord(entry) || typeof entry.into !== "string") continue;
    const into = entry.into.trim().toLowerCase();
    // A unit nobody holds is a typo in one extraction, and a unit that morphs
    // into itself is an edge with nothing to say. Neither should group anything.
    if (!into || into === self || !held.has(into) || seen.has(into)) continue;
    seen.add(into);
    edges.push({
      into,
      conditions: Object.fromEntries(
        Object.entries(entry).filter(([field]) => field !== "into"),
      ),
    });
  }
  return edges.sort((a, b) => byCodeUnit(a.into, b.into));
}

export function morphGroups(units: MorphUnit[]): MorphGroup[] {
  const held = new Set(units.map((unit) => unit.unit_name.toLowerCase()));
  const stored = new Map(units.map((unit) => [unit.unit_name.toLowerCase(), unit.unit_name]));

  const out = new Map<string, MorphEdge[]>();
  const neighbours = new Map<string, Set<string>>();
  const incoming = new Map<string, number>();
  const touch = (key: string) => {
    if (!neighbours.has(key)) neighbours.set(key, new Set());
    return neighbours.get(key) as Set<string>;
  };

  for (const unit of units) {
    const key = unit.unit_name.toLowerCase();
    const edges = edgesOf(unit, held);
    if (edges.length === 0) continue;
    out.set(key, edges);
    for (const edge of edges) {
      touch(key).add(edge.into);
      touch(edge.into).add(key);
      incoming.set(edge.into, (incoming.get(edge.into) ?? 0) + 1);
    }
  }

  // The components, direction ignored. A unit with no edge either way is not
  // in `neighbours` at all, so a group of one never arises.
  const claimed = new Set<string>();
  const groups: MorphGroup[] = [];
  for (const start of [...neighbours.keys()].sort(byCodeUnit)) {
    if (claimed.has(start)) continue;

    const members: string[] = [];
    const queue = [start];
    claimed.add(start);
    while (queue.length > 0) {
      const key = queue.shift() as string;
      members.push(key);
      for (const next of [...(neighbours.get(key) ?? [])].sort(byCodeUnit)) {
        if (claimed.has(next)) continue;
        claimed.add(next);
        queue.push(next);
      }
    }

    groups.push(groupOf(members, out, incoming, stored));
  }

  return groups.sort((a, b) => byCodeUnit(a.base, b.base));
}

/** One component, based and ordered. */
function groupOf(
  members: string[],
  out: ReadonlyMap<string, MorphEdge[]>,
  incoming: ReadonlyMap<string, number>,
  stored: ReadonlyMap<string, string>,
): MorphGroup {
  const sorted = [...members].sort(byCodeUnit);
  // The unit nothing morphs into. A cycle has none, and then the first member
  // by code unit heads it: a tie break, rather than an exception the readers
  // would each have to handle.
  const base = sorted.find((key) => (incoming.get(key) ?? 0) === 0) ?? sorted[0];

  // Breadth first from the base, so a stage always follows the stage it is
  // reached from and `from` reads as the level before this one.
  const from = new Map<string, { from: string; conditions: Record<string, unknown> }>();
  const order: string[] = [];
  const walked = new Set<string>([base]);
  const queue = [base];
  while (queue.length > 0) {
    const key = queue.shift() as string;
    order.push(key);
    for (const edge of out.get(key) ?? []) {
      if (walked.has(edge.into)) continue;
      walked.add(edge.into);
      from.set(edge.into, { from: key, conditions: edge.conditions });
      queue.push(edge.into);
    }
  }

  // Anything the base cannot reach: the far side of a converging group, whose
  // own root the base is not. Appended rather than dropped, and reached from
  // nothing rather than given a predecessor it never had.
  for (const key of sorted) {
    if (!walked.has(key)) order.push(key);
  }

  return {
    base,
    stages: order.map((key) => ({
      name: key,
      unit_name: stored.get(key) ?? key,
      from: from.get(key)?.from ?? null,
      conditions: from.get(key)?.conditions ?? {},
      into: out.get(key) ?? [],
    })),
  };
}

/**
 * Every grouped unit's base, keyed on the lower cased def.
 *
 * A unit in no group is absent rather than mapped to itself, so a caller can
 * tell "this has no stages" from "this is its own base" without a second
 * lookup.
 */
export function baseIndex(groups: MorphGroup[]): ReadonlyMap<string, string> {
  const bases = new Map<string, string>();
  for (const group of groups) {
    for (const stage of group.stages) bases.set(stage.name, group.base);
  }
  return bases;
}
