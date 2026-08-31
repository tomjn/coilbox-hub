import type { SupabaseClient } from "@supabase/supabase-js";
import { baseIndex, morphGroups } from "./morph";

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
 *
 * A morph chain is one node (#295). The levels of an upgrading commander fold
 * into their base before the walk starts, so what any level builds hangs off
 * the one node and a builder that makes level three points at the same node as
 * one that makes level one. `lib/games/morph.ts` decides the grouping and this
 * file only reads it. The fold happens after the faction scope has been
 * applied, so a chain whose levels report different sides groups within the
 * side being shown rather than dragging another side's rows into the walk.
 */

export interface TreeNode {
  /** The def key, lowercased, which is what build options name. */
  name: string;
  /** What a reader sees: the full name where the catalog holds one. */
  label: string;
  /** What this unit builds, as keys into the same tree. Sorted. */
  builds: string[];
  /** Whether the unit's own facts report weapons (#278). Builders read
   *  separately through {@link TreeNode.builds}, since the two answers draw
   *  differently: a wall that shoots and a plant that builds are not the same
   *  kind of row. A folded morph group is armed when any of its stages is,
   *  because the node stands for the whole life of the unit. */
  armed: boolean;
  /** How many stages this node stands for (#295). 1 for an ordinary unit, and
   *  the size of the group for a commander that upgrades through tech levels. */
  stages: number;
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
/**
 * Whether a unit's facts report a weapons summary.
 *
 * The summary arrives as a stat whose value is an array of records (#261), the
 * same shape {@link tabularStatRows} draws as a table. An empty array is not
 * armed: it says the extraction measured none, and zero is not some.
 */
function armedOf(stats: Record<string, unknown> | null | undefined): boolean {
  const weapons = stats?.weapons;
  return Array.isArray(weapons) && weapons.length > 0;
}

export function buildTree(
  units: {
    unit_name: string;
    full_name: string | null;
    build_options: string[];
    stats?: Record<string, unknown> | null;
    morph_targets?: unknown;
  }[],
  roots: string[],
): Tree {
  // The morph groups, decided before anything else reads a build option, so
  // every lookup below is already speaking in bases.
  const groups = morphGroups(
    units.map((unit) => ({ unit_name: unit.unit_name, morph_targets: unit.morph_targets })),
  );
  const bases = baseIndex(groups);
  const baseOf = (key: string) => bases.get(key) ?? key;

  const rowOf = new Map<string, (typeof units)[number]>();
  for (const unit of units) rowOf.set(unit.unit_name.toLowerCase(), unit);

  /** One node's facts: a plain unit's own, or a whole group's folded together. */
  const known = new Map<
    string,
    { full_name: string | null; options: string[]; armed: boolean; stages: number }
  >();

  // Groups first, so a folded entry takes its name and its label from the base
  // whatever order the rows arrived in.
  for (const group of groups) {
    const options = new Set<string>();
    let armed = false;
    for (const stage of group.stages) {
      const row = rowOf.get(stage.name);
      for (const option of row?.build_options ?? []) {
        const key = option?.toLowerCase();
        if (key) options.add(key);
      }
      if (armedOf(row?.stats)) armed = true;
    }
    known.set(group.base, {
      full_name: rowOf.get(group.base)?.full_name ?? null,
      options: [...options],
      armed,
      stages: group.stages.length,
    });
  }

  for (const unit of units) {
    const key = unit.unit_name.toLowerCase();
    // A level already folded into its base is not a node of its own.
    if (bases.has(key)) continue;
    known.set(key, {
      full_name: unit.full_name,
      options: (unit.build_options ?? []).map((option) => option?.toLowerCase()).filter(Boolean),
      armed: armedOf(unit.stats),
      stages: 1,
    });
  }

  const labelOf = (key: string) => known.get(key)?.full_name ?? key;

  // What each node builds, resolved once: every option points at a base, the
  // dangling ones are dropped as they always were, and a node never lists
  // itself. That last one only arises from the fold - a plant that builds
  // level three of the commander that builds the plant would otherwise read as
  // building itself once both are folded.
  const buildsOf = new Map<string, string[]>();
  for (const [key, node] of known) {
    buildsOf.set(
      key,
      [...new Set(node.options.map(baseOf))]
        .filter((option) => option !== key && known.has(option))
        .sort(),
    );
  }

  // The roots that actually exist. A start unit the catalog has never heard of
  // would head an empty block forever, so it is dropped here where the reason
  // is visible rather than rendered as a heading over nothing. A start unit
  // naming a level heads the group that level belongs to.
  const rootKeys: string[] = [];
  const factionOf = new Map<string, string>();
  for (const root of roots) {
    const key = root ? baseOf(root.toLowerCase()) : "";
    if (key && known.has(key) && !factionOf.has(key)) {
      factionOf.set(key, key);
      rootKeys.push(key);
    }
  }

  const queue = rootKeys.map((key) => [key, key] as const);
  while (queue.length > 0) {
    const [node, root] = queue.shift() ?? [];
    if (!node || !root) break;
    for (const next of buildsOf.get(node) ?? []) {
      if (factionOf.has(next)) continue;
      factionOf.set(next, root);
      queue.push([next, root]);
    }
  }

  const nodeOf = (key: string): TreeNode => ({
    name: key,
    label: labelOf(key),
    builds: buildsOf.get(key) ?? [],
    armed: known.get(key)?.armed ?? false,
    stages: known.get(key)?.stages ?? 1,
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

  // A unit nothing builds and no start unit heads is a reference somebody
  // kept in the archive, not a thing a player can reach (#280): it hides
  // rather than filling the ungrouped block with ghosts. A mention from
  // another unreachable unit still counts as built-by-something, so an
  // orphaned cluster stays visible instead of vanishing chain by chain.
  // Resolved through the fold, so a builder that makes level three counts as a
  // mention of the commander. Without that, folding a level would strand the
  // group it folded into and hide a unit a player can plainly reach.
  const referenced = new Set<string>();
  for (const unit of units) {
    for (const option of unit.build_options) {
      const key = option?.toLowerCase();
      if (key) referenced.add(baseOf(key));
    }
  }

  const ungrouped = [...known.keys()]
    .filter((key) => !factionOf.has(key))
    .filter((key) => referenced.has(key))
    .sort()
    .map(nodeOf);

  return { factions, ungrouped };
}

/** One unit as the walk reads it. */
interface TreeUnit {
  unit_name: string;
  full_name: string | null;
  build_options: string[];
  faction_key: string | null;
  /** The unit's own facts, read for the weapons summary alone. Only whether
   *  that summary exists reaches the tree (#278). */
  stats: Record<string, unknown> | null;
  /** What this unit turns into, as stored (#295). */
  morph_targets: unknown;
}

/**
 * One game's tree: current facts, or what one release said.
 *
 * `factionKey` scopes the whole walk to one side's units (#258), so a reader
 * choosing Arm gets Arm's blocks and nothing else. Roots come from
 * `game.start_units`, which is not versioned: which sides a game has is stable
 * across releases in a way stats are not, and a root nobody reports is dropped
 * by the walk rather than rendered as an empty heading.
 */
export async function loadTree(
  supabase: SupabaseClient,
  shortname: string,
  version?: string,
  factionKey?: string | null,
): Promise<Tree | null> {
  const [units, game] = await Promise.all([
    version
      ? supabase
          .from("game_unit")
          .select(
            "unit_name,full_name,faction_key,stats,build_options,morph_targets,game!inner(shortname)," +
              "game_unit_revision!inner(version,full_name,faction_key,build_options,morph_targets,stats)",
          )
          .eq("game.shortname", shortname)
          .eq("game_unit_revision.version", version)
      : supabase
          .from("game_unit")
          .select(
            "unit_name,full_name,faction_key,build_options,morph_targets,stats,game!inner(shortname)",
          )
          .eq("game.shortname", shortname)
          // `is`, not `eq`: PostgREST refuses `removed_at=eq.null`, and a
          // refused read here is a null tree, which the page shows as a 404
          // (#255).
          .is("removed_at", null),
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
            faction_key: string | null;
            stats: Record<string, unknown> | null;
            morph_targets: unknown;
            game_unit_revision: {
              full_name: string | null;
              faction_key: string | null;
              build_options: string[];
              morph_targets: unknown;
              stats: Record<string, unknown> | null;
            }[];
          }[]
        ).map((row) => ({
          unit_name: row.unit_name,
          full_name: row.game_unit_revision[0]?.full_name ?? row.full_name,
          build_options: row.game_unit_revision[0]?.build_options ?? [],
          faction_key: row.game_unit_revision[0]?.faction_key ?? row.faction_key,
          stats: row.game_unit_revision[0]?.stats ?? row.stats ?? null,
          // The release's own grouping, so an old page's chain is the chain
          // that release reported rather than today's.
          morph_targets: row.game_unit_revision[0]?.morph_targets ?? row.morph_targets,
        }))
      : (units.data as unknown as TreeUnit[])
  ).map((row) => ({
    unit_name: row.unit_name,
    full_name: row.full_name,
    build_options: row.build_options ?? [],
    faction_key: row.faction_key ?? null,
    stats: row.stats ?? null,
    morph_targets: row.morph_targets ?? [],
  }));

  const scoped = factionKey ? rows.filter((row) => row.faction_key === factionKey) : rows;

  return buildTree(scoped, game.data.start_units ?? []);
}
