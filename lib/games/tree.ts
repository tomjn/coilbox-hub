import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The build tree (#228): a game's units grouped by faction, rooted at the start
 * units, walkable down through what each thing builds.
 *
 * The grouping rule is coilbox's own (`src/content/techForest.ts`), because the
 * question is the same question and one answer should not be two algorithms: a
 * multi-source walk seeds every root first, then assigns each other unit to the
 * first root that reaches it. Dangling build options are dropped, and units no
 * root reaches land in an explicit ungrouped block rather than being hidden.
 *
 * It is a grouping, not a hierarchy, for the reason upstream records: a unit two
 * builders make appears under its faction once, but every builder that can make
 * it lists it under itself, so nothing is invisible because a spanning tree had
 * to pick one parent.
 */

export interface TreeNode {
  /** The def key, lowercased, which is what build options name. */
  name: string;
  /** What a reader sees: the full name where the catalog holds one. */
  label: string;
  /** What this unit builds, as keys into the same tree. Sorted. */
  builds: string[];
}

export interface TreeFaction {
  /** The root unit's key, which heads the block. */
  root: string;
  label: string;
  units: TreeNode[];
}

export interface Tree {
  factions: TreeFaction[];
  /** Known units no root reaches, sorted. Present rather than hidden. */
  ungrouped: TreeNode[];
}

/**
 * Group a game's units by faction.
 *
 * Keys are lowercased throughout, matching how build options arrive and how the
 * worker reports def keys, so lookups are case-insensitive by construction
 * rather than by everybody remembering to normalise at every use.
 */
export function buildTree(
  units: { unit_name: string; full_name: string | null; build_options: string[] }[],
  roots: string[],
): Tree {
  const known = new Map<string, { unit_name: string; full_name: string | null; build_options: string[] }>();
  for (const unit of units) {
    known.set(unit.unit_name.toLowerCase(), unit);
  }

  const labelOf = (key: string) => known.get(key)?.full_name ?? key;

  // The roots that actually exist. A start unit the catalog has never heard of
  // would head an empty block forever, so it is dropped here where the reason
  // is visible rather than rendered as a heading over nothing.
  const rootKeys: string[] = [];
  const factionOf = new Map<string, string>();
  for (const root of roots) {
    const key = root?.toLowerCase();
    if (key && known.has(key) && !factionOf.has(key)) {
      factionOf.set(key, key);
      rootKeys.push(key);
    }
  }

  const queue = rootKeys.map((key) => [key, key] as const);
  while (queue.length > 0) {
    const [node, root] = queue.shift() ?? [];
    if (!node || !root) break;
    for (const option of known.get(node)?.build_options ?? []) {
      const next = option?.toLowerCase();
      if (!next || !known.has(next) || factionOf.has(next)) continue;
      factionOf.set(next, root);
      queue.push([next, root]);
    }
  }

  const nodeOf = (key: string): TreeNode => ({
    name: key,
    label: labelOf(key),
    builds: [...new Set(known.get(key)?.build_options ?? [])]
      .map((option) => option.toLowerCase())
      .filter((option) => known.has(option))
      .sort(),
  });

  const factions = rootKeys.map((root) => ({
    root,
    label: labelOf(root),
    units: [...factionOf.entries()]
      .filter(([, faction]) => faction === root)
      .map(([key]) => key)
      .sort()
      .map(nodeOf),
  }));

  const ungrouped = [...known.keys()]
    .filter((key) => !factionOf.has(key))
    .sort()
    .map(nodeOf);

  return { factions, ungrouped };
}

/** Which entries survive a search, by name or by what a reader calls them. */
export function matchesQuery(node: TreeNode, q: string | null): boolean {
  if (!q) return true;
  const needle = q.toLowerCase();
  return node.name.includes(needle) || node.label.toLowerCase().includes(needle);
}

/** One unit as the walk reads it. */
interface TreeUnit {
  unit_name: string;
  full_name: string | null;
  build_options: string[];
}

/**
 * One game's tree: current facts, or what one release said.
 *
 * Roots come from `game.start_units`, which is not versioned: which sides a
 * game has is stable across releases in a way stats are not, and a root nobody
 * reports is dropped by the walk rather than rendered as an empty heading.
 */
export async function loadTree(
  supabase: SupabaseClient,
  shortname: string,
  version?: string,
): Promise<Tree | null> {
  const [units, game] = await Promise.all([
    version
      ? supabase
          .from("game_unit")
          .select("unit_name,full_name,game!inner(shortname),game_unit_revision!inner(full_name,build_options)")
          .eq("game.shortname", shortname)
          .eq("game_unit_revision.version", version)
      : supabase
          .from("game_unit")
          .select("unit_name,full_name,build_options,game!inner(shortname)")
          .eq("game.shortname", shortname)
          .eq("removed_at", null),
    supabase.from("game").select("start_units").eq("shortname", shortname).maybeSingle(),
  ]);

  if (units.error || !units.data || game.error || !game.data) return null;

  // A versioned read rides the inner join to the revision and reads from there;
  // a current read has the columns on the row itself.
  const rows: TreeUnit[] = (
    version
      ? (
          units.data as unknown as {
            unit_name: string;
            full_name: string | null;
            game_unit_revision: { full_name: string | null; build_options: string[] }[];
          }[]
        ).map((row) => ({
          unit_name: row.unit_name,
          full_name: row.game_unit_revision[0]?.full_name ?? row.full_name,
          build_options: row.game_unit_revision[0]?.build_options ?? [],
        }))
      : (units.data as unknown as TreeUnit[])
  ).map((row) => ({ unit_name: row.unit_name, full_name: row.full_name, build_options: row.build_options ?? [] }));

  return buildTree(rows, game.data.start_units ?? []);
}
