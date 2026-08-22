import type { SupabaseClient } from "@supabase/supabase-js";
import {
  UNIT_BUILDPIC_VARIANT,
  UNIT_TOP_RENDER_VARIANT,
  type AssetIdentity,
} from "@/lib/assets/asset";
import { fetchHeldAssets, resolveAsset, type ResolvedAsset } from "@/lib/assets/resolve";
import { fetchPage } from "@/lib/gallery/query";
import { formatStatValue, statLabel, statRows } from "./stats";

/**
 * The encyclopedia's reads (#227): a grid of a game's units, one unit's page,
 * and the two versions a compare view puts side by side.
 *
 * Everything here reads through row level security with an anonymous client.
 * The facts are public, and so are the pictures: `fetchHeldAssets` filters on
 * approved only, which is the same line every other page's pictures sit behind.
 *
 * Every read below narrows to one game by filtering on `game.shortname`, and
 * PostgREST refuses such a filter unless the request also embeds `game` in its
 * select (#250). That embedded column is never read by TypeScript; dropping it
 * brings back the PGRST108 these reads used to answer.
 */

export const UNIT_PAGE_SIZE = 48;

/** One cell of the grid. */
export interface UnitSummary {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
}

/** The grid's own shape of the query string, parsed and bounded. */
export interface UnitGridFilters {
  q: string | null;
  /** Retired units are hidden by default; a balance patch that removed a unit
   *  did not erase it, and this is how it is found again. */
  retired: boolean;
  /** Narrow the grid to one faction's key (#258). Null shows every faction. */
  faction: string | null;
  page: number;
}

export function parseUnitGridFilters(
  params: Record<string, string | string[] | undefined>,
): UnitGridFilters {
  const first = (value: string | string[] | undefined) =>
    Array.isArray(value) ? value[0] : value;

  const q = first(params.q)?.trim() || null;
  const retired = first(params.retired) === "1";
  const faction = first(params.faction)?.trim() || null;
  const rawPage = Number(first(params.page));
  const page = Number.isSafeInteger(rawPage) && rawPage > 1 ? rawPage : 1;
  return { q, retired, faction, page };
}

interface UnitRow {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
}

export async function loadUnitGrid(
  supabase: SupabaseClient,
  shortname: string,
  filters: UnitGridFilters,
): Promise<{ units: UnitSummary[]; count: number; error: string | null }> {
  let query = supabase
    .from("game_unit")
    .select("unit_name,full_name,faction_key,game!inner(shortname)", { count: "exact" })
    .eq("game.shortname", shortname);

  // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` outright, so this
  // took out the whole grid rather than hiding the retired units (#255).
  if (!filters.retired) query = query.is("removed_at", null);
  if (filters.faction) query = query.eq("faction_key", filters.faction);
  if (filters.q) {
    // Either name is a name. A visitor who typed "commander" is looking for
    // armcom, whose full name says Commander and whose def key does not.
    query = query.or(`unit_name.ilike.%${filters.q}%,full_name.ilike.%${filters.q}%`);
  }

  const listing = () =>
    query.order("unit_name").range(
      (filters.page - 1) * UNIT_PAGE_SIZE,
      filters.page * UNIT_PAGE_SIZE - 1,
    );

  const { data, count, error } = await fetchPage<UnitRow>(
    () => listing(),
    async () => {
      const held = await listing().range(0, 0);
      return { count: held.count, error: held.error };
    },
  );

  if (error) return { units: [], count: 0, error };
  return { units: (data ?? []) as unknown as UnitRow[], count: count ?? 0, error: null };
}

/** The buildpic for every unit in a page of the grid, keyed by unit name. */
export async function unitBuildpics(
  supabase: SupabaseClient,
  shortname: string,
  units: UnitSummary[],
): Promise<ReadonlyMap<string, ResolvedAsset>> {
  const identities: AssetIdentity[] = units.map((unit) => ({
    keyedOn: "unit",
    game: shortname,
    unitName: unit.unit_name,
    variant: UNIT_BUILDPIC_VARIANT,
  }));

  const held = await fetchHeldAssets(supabase, identities);
  const pictures = new Map<string, ResolvedAsset>();
  for (const [index, identity] of identities.entries()) {
    const unit = units[index];
    pictures.set(
      unit.unit_name,
      resolveAsset(identity, held, null),
    );
  }
  return pictures;
}

/**
 * Every side a game has, alphabetical as the game page orders them.
 *
 * A unit points at its faction by key and deliberately not by foreign key, so
 * names arrive from one read of the game's factions rather than an embed that
 * has no relationship to ride. This is also what the faction filters on the
 * encyclopedia and the build tree offer as choices (#258), so both pages agree
 * on what the sides are and what they are called.
 */
export interface FactionOption {
  key: string;
  name: string;
}

export async function gameFactions(
  supabase: SupabaseClient,
  shortname: string,
): Promise<FactionOption[]> {
  const { data } = await supabase
    .from("game_faction")
    .select("key,name,game!inner(shortname)")
    .eq("game.shortname", shortname);
  return ((data ?? []) as unknown as { key: string; name: string }[]).map((f) => ({
    key: f.key,
    name: f.name,
  }));
}

async function factionNames(
  supabase: SupabaseClient,
  shortname: string,
): Promise<Map<string, string>> {
  const factions = await gameFactions(supabase, shortname);
  return new Map(factions.map((f) => [f.key, f.name]));
}

/** One unit's page: current facts, or what one release said. */
export interface UnitPage {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  faction_name: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
  /** The author's own words, where an owner has written any (#229). */
  snippet: string | null;
  /** Where these facts came from: the release named here is the release that
   *  reported them. Null when no client has said anything yet. */
  source_version: string | null;
  /** Set when the visitor asked for an older version, which is when the page
   *  shows a revision rather than the current row. */
  shown_version: string | null;
  removed_at: string | null;
  /** Every release the picker offers, newest first. */
  versions: string[];
  /** What this unit builds, with each entry's display name where the catalog
   *  holds one. Sorted, because the array order is not a fact. */
  builds: { name: string; label: string }[];
}

export async function loadUnitPage(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
  version?: string,
): Promise<UnitPage | null> {
  const [held, versions, factions] = await Promise.all([
    supabase
      .from("game_unit")
      .select(
        "id,unit_name,full_name,faction_key,build_options,stats,snippet,source_version,removed_at," +
          "game!inner(shortname)," +
          "game_unit_revision(version,full_name,faction_key,build_options,stats)",
      )
      .eq("game.shortname", shortname)
      .eq("unit_name", unitName)
      .order("version", { referencedTable: "game_unit_revision", ascending: false })
      .limit(1, { referencedTable: "game_unit_revision" })
      .maybeSingle(),
    supabase
      .from("game_version")
      .select("version,last_seen_at,game!inner(shortname)")
      .eq("game.shortname", shortname)
      .order("last_seen_at", { ascending: false }),
    factionNames(supabase, shortname),
  ]);

  const row = held.data as
    | {
        id: number;
        unit_name: string;
        full_name: string | null;
        faction_key: string | null;
        build_options: string[];
        stats: Record<string, unknown>;
        snippet: string | null;
        source_version: string | null;
        removed_at: string | null;
        game_unit_revision:
          | { version: string; full_name: string | null; faction_key: string | null; build_options: string[]; stats: Record<string, unknown> }[]
          | null;
      }
    | null;

  if (held.error || !row || versions.error) return null;

  // An older version means the revision, when one exists. When it does not, the
  // page still renders - with the current facts and a line saying this release
  // has no record - because a unit existing today and not in some old release
  // is an ordinary answer, not a missing page.
  const asked = version ?? null;
  const revision = asked ? (row.game_unit_revision?.find((r) => r.version === asked) ?? null) : null;

  const fullName = revision ? revision.full_name : row.full_name;
  const factionKey = revision ? revision.faction_key : row.faction_key;
  const buildOptions = revision ? revision.build_options : row.build_options;
  const stats = revision ? revision.stats : row.stats;

  // What it builds, labelled by whatever the catalog calls each entry. Only the
  // named units are read, since a game has hundreds of units and a commander
  // builds a couple of dozen of them. A build option nobody holds is still
  // listed under its own key: dropping it would hide exactly the edge a reader
  // came to check.
  const options = [...new Set(buildOptions ?? [])].sort();
  const builds =
    options.length === 0
      ? []
      : await supabase
          .from("game_unit")
          .select("unit_name,full_name,game!inner(shortname)")
          .eq("game.shortname", shortname)
          .in("unit_name", options)
          .then(({ data }) => {
            const names = new Map<string, string>(
              ((data ?? []) as unknown as { unit_name: string; full_name: string | null }[]).map(
                (entry) => [entry.unit_name, entry.full_name ?? entry.unit_name],
              ),
            );
            return options.map((name) => ({ name, label: names.get(name) ?? name }));
          });

  return {
    unit_name: row.unit_name,
    full_name: fullName,
    faction_key: factionKey,
    faction_name: factionKey ? (factions.get(factionKey) ?? null) : null,
    build_options: buildOptions ?? [],
    stats: stats ?? {},
    snippet: row.snippet,
    source_version: row.source_version,
    shown_version: revision ? asked : null,
    removed_at: row.removed_at,
    versions: ((versions.data ?? []) as unknown as { version: string }[]).map((v) => v.version),
    builds,
  };
}

/** The top down render for a unit's page, falling back to its buildpic through
 *  the ladder `lib/assets/resolve.ts` owns. */
export async function unitRender(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
): Promise<ResolvedAsset> {
  const identity: AssetIdentity = {
    keyedOn: "unit",
    game: shortname,
    unitName,
    variant: UNIT_TOP_RENDER_VARIANT,
  };
  const held = await fetchHeldAssets(supabase, [identity]);
  return resolveAsset(identity, held, null);
}

/** One side of a compare view: what one release said about one unit. */
interface ComparisonSide {
  version: string;
  /** False when the hub holds no revision for this release, which is an
   *  ordinary answer for a unit a patch added or retired. */
  found: boolean;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
}

export interface UnitComparison {
  unit_name: string;
  left: ComparisonSide;
  right: ComparisonSide;
  /** Every stat key either side carries, in reading order, with both values
   *  formatted and the ones that differ marked. */
  rows: { key: string; label: string; left: string; right: string; changed: boolean }[];
}

const NOT_RECORDED: Omit<ComparisonSide, "version"> = {
  found: false,
  full_name: null,
  faction_key: null,
  build_options: [],
  stats: {},
};

export async function loadUnitComparison(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
  leftVersion: string,
  rightVersion: string,
): Promise<UnitComparison | null> {
  const held = await supabase
    .from("game_unit")
    .select(
      "unit_name," +
        "game!inner(shortname)," +
        "game_unit_revision(version,full_name,faction_key,build_options,stats)",
    )
    .eq("game.shortname", shortname)
    .eq("unit_name", unitName)
    .order("version", { referencedTable: "game_unit_revision", ascending: false })
    .maybeSingle();

  const row = held.data as
    | {
        unit_name: string;
        game_unit_revision:
          | { version: string; full_name: string | null; faction_key: string | null; build_options: string[]; stats: Record<string, unknown> }[]
          | null;
      }
    | null;

  if (held.error || !row) return null;

  const side = (version: string): ComparisonSide => {
    const revision = row.game_unit_revision?.find((r) => r.version === version);
    return revision
      ? {
          version,
          found: true,
          full_name: revision.full_name,
          faction_key: revision.faction_key,
          build_options: revision.build_options,
          stats: revision.stats,
        }
      : { version, ...NOT_RECORDED };
  };

  const left = side(leftVersion);
  const right = side(rightVersion);

  // The union of both sides' keys, so a stat one release introduced is still
  // on the table: the side without it reads as not recorded, which is the fact.
  const ordered = [
    ...statRows(left.stats).map((row) => row.key),
    ...statRows(right.stats).map((row) => row.key),
  ].filter((key, index, all) => all.indexOf(key) === index);

  return {
    unit_name: row.unit_name,
    left,
    right,
    rows: ordered.map((key) => ({
      key,
      label: statLabel(key),
      left: formatStatValue(left.stats[key] ?? null),
      right: formatStatValue(right.stats[key] ?? null),
      changed: formatStatValue(left.stats[key] ?? null) !== formatStatValue(right.stats[key] ?? null),
    })),
  };
}
