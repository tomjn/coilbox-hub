import type { SupabaseClient } from "@supabase/supabase-js";
import {
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_ANGLES,
  UNIT_RENDER_VARIANT_PREFIX,
  type AssetIdentity,
} from "@/lib/assets/asset";
import { fetchHeldAssets, resolveAsset, type ResolvedAsset } from "@/lib/assets/resolve";
import { fetchPage } from "@/lib/gallery/query";
import { readAll } from "@/lib/supabase/readAll";
import { morphGroups, type MorphStage } from "./morph";
import { formatStatValue, statLabel, statRows, tabularStatRows } from "./stats";

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
  /** Units nothing builds and no start unit heads (#280): off the shelf. */
  exclude: string[] = [],
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
  if (exclude.length > 0) {
    // Def keys are lowercased words, so no list here needs CSV quoting.
    query = query.not("unit_name", "in", `(${exclude.join(",")})`);
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

/**
 * The units a player can never reach (#280): nothing builds them and no start
 * unit heads them. An archive keeps reference defs among a game's facts - old
 * units, experiments - and a shelf of ghosts helps nobody.
 *
 * A mention counts only from a living row: the one build option a retired unit
 * holds is not a live path either. The answer feeds an exclusion list, so it
 * stays empty on any read that fails rather than taking the grid down with it.
 */
export async function unbuildableUnits(
  supabase: SupabaseClient,
  shortname: string,
): Promise<string[]> {
  const [units, game] = await Promise.all([
    // Paged, because a mention is only absent if the whole game was looked at:
    // a truncated read would call every unit past the cap a ghost and empty
    // half the shelf.
    readAll<{ unit_name: string; build_options: string[] }>((from, to) =>
      supabase
        .from("game_unit")
        .select("unit_name,build_options,game!inner(shortname)")
        .eq("game.shortname", shortname)
        .is("removed_at", null)
        .order("unit_name")
        .range(from, to),
    ),
    supabase.from("game").select("start_units").eq("shortname", shortname).maybeSingle(),
  ]);
  if (!units || game.error || !game.data) return [];

  const rows = units;
  const starts = new Set(
    ((game.data.start_units ?? []) as string[]).map((name) => name.toLowerCase()),
  );
  const referenced = new Set<string>();
  for (const row of rows) {
    for (const option of row.build_options ?? []) {
      const key = option?.toLowerCase();
      if (key) referenced.add(key);
    }
  }
  return rows
    .map((row) => row.unit_name)
    .filter((name) => !referenced.has(name.toLowerCase()) && !starts.has(name.toLowerCase()))
    .sort();
}

/** One unit as the morph walk reads it. */
interface MorphRow {
  unit_name: string;
  morph_targets: unknown;
}

/**
 * Every unit of a game beside what it turns into.
 *
 * Grouping is the read a truncated answer makes wrong rather than short: a
 * chain whose upper levels fell past the row cap silently stops being a chain,
 * and the grid grows back the cells the grouping exists to remove. So it goes
 * through {@link readAll}, which says why one request is not a whole game.
 */
async function morphRows(
  supabase: SupabaseClient,
  shortname: string,
  liveOnly: boolean,
  version?: string | null,
): Promise<MorphRow[] | null> {
  const rows = await readAll<MorphRow & { game_unit_revision?: { morph_targets: unknown }[] | null }>(
    (from, to) => {
      // Filtered rather than inner joined, so a unit the release has no record
      // of keeps its row and reports no edges, instead of dropping out and
      // taking the rest of its chain's shape with it.
      let query = supabase
        .from("game_unit")
        .select(
          version
            ? "unit_name,morph_targets,game!inner(shortname),game_unit_revision(version,morph_targets)"
            : "unit_name,morph_targets,game!inner(shortname)",
        )
        .eq("game.shortname", shortname);
      // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` (#255).
      if (liveOnly) query = query.is("removed_at", null);
      if (version) query = query.eq("game_unit_revision.version", version);
      return query.order("unit_name").range(from, to);
    },
  );
  if (!rows) return null;

  return rows.map((row) => ({
    unit_name: row.unit_name,
    // A release that reported nothing about this unit reported no edges for
    // it. Falling back to today's would draw a chain that release never had.
    morph_targets: version
      ? (row.game_unit_revision?.[0]?.morph_targets ?? [])
      : row.morph_targets,
  }));
}

/**
 * The levels of a morph chain that are not its base (#295).
 *
 * A five level commander is one unit at five stages of its life, so the grid
 * shows one cell and the other four hide. The answer feeds the same exclusion
 * list the ghosts ride into the query, so paging and the count are computed
 * over what the grid actually shows rather than filtered afterwards.
 *
 * Live units only, the way {@link unbuildableUnits} reads. A retired level is
 * not part of the game any more, so it neither heads a group nor drags its
 * live levels off the shelf behind it: a patch that retired level one leaves
 * level two heading the cell. A unit's own page groups over retired levels too
 * ({@link loadUnitPage}), because the stages there are the unit's history and a
 * removed level still has a page (#227).
 *
 * Names come back spelled as they are stored, because the exclusion is a
 * `unit_name` filter and PostgREST matches it exactly. Empty on a failed read
 * rather than taking the grid down with it.
 */
export async function morphedAwayUnits(
  supabase: SupabaseClient,
  shortname: string,
): Promise<string[]> {
  const rows = await morphRows(supabase, shortname, true);
  if (!rows) return [];

  return morphGroups(rows)
    .flatMap((group) => group.stages.filter((stage) => stage.name !== group.base))
    .map((stage) => stage.unit_name)
    .sort();
}

/** One stage of a unit's life, as its page draws it (#295). */
export interface UnitStage {
  /** The name as stored, which is this stage's own URL segment. */
  unit_name: string;
  label: string;
  /** Whether this is the stage the page is showing. */
  current: boolean;
  /** The stage this one is reached from, as stored. Null for the base, and for
   *  a stage nothing else in the group turns into. */
  from: string | null;
  /** How you get here, in the game's own words: whatever the extraction put on
   *  the edge beside the unit it names. Empty when there is no such edge. */
  conditions: Record<string, unknown>;
  /** What this stage builds that the stage before it could not. The reason
   *  somebody upgrades, which is the fact this page exists to carry. */
  unlocks: { name: string; label: string }[];
  stats: Record<string, unknown>;
  /** False when the release being shown has no record of this stage, which is
   *  the ordinary answer for a level a later patch added. */
  found: boolean;
  removed_at: string | null;
}

/** One row of the stats table that runs across the stages: every stat any
 *  stage carries, one value per stage in stage order. */
export interface StageStatRow {
  key: string;
  label: string;
  values: string[];
  /** True when the stages do not all agree, which is what a reader comparing
   *  levels is looking for. */
  changed: boolean;
}

interface StageRow {
  unit_name: string;
  full_name: string | null;
  build_options: string[];
  stats: Record<string, unknown> | null;
  removed_at: string | null;
  game_unit_revision?:
    | { full_name: string | null; build_options: string[]; stats: Record<string, unknown> | null }[]
    | null;
}

/**
 * The stages of one unit's life (#295).
 *
 * A commander that upgrades through tech levels is one unit at five stages,
 * and this is what the page puts above the stat table: the levels in order,
 * what each one costs to reach, and what each one unlocks.
 *
 * Grouped over every unit the game holds, retired ones included, because a
 * level a patch removed is still part of how this unit got here and still has
 * a page (#227). The grid groups over live units only, and
 * {@link morphedAwayUnits} says why the two differ.
 *
 * Empty when the unit turns into nothing and nothing turns into it, which is
 * how the page knows to draw no strip at all rather than a strip of one.
 */
export async function loadUnitStages(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
  version?: string | null,
): Promise<{ stages: UnitStage[]; stage_stats: StageStatRow[] }> {
  const none = { stages: [], stage_stats: [] };

  const rows = await morphRows(supabase, shortname, false, version);
  if (!rows) return none;

  const key = unitName.toLowerCase();
  const group = morphGroups(rows).find((candidate) =>
    candidate.stages.some((stage) => stage.name === key),
  );
  if (!group) return none;

  const held = version
    ? await supabase
        .from("game_unit")
        .select(
          "unit_name,full_name,build_options,stats,removed_at,game!inner(shortname)," +
            "game_unit_revision(version,full_name,build_options,stats)",
        )
        .eq("game.shortname", shortname)
        .in("unit_name", group.stages.map((stage) => stage.unit_name))
        .eq("game_unit_revision.version", version)
    : await supabase
        .from("game_unit")
        .select("unit_name,full_name,build_options,stats,removed_at,game!inner(shortname)")
        .eq("game.shortname", shortname)
        .in("unit_name", group.stages.map((stage) => stage.unit_name));

  if (held.error || !held.data) return none;

  const facts = new Map<string, StageRow>();
  for (const row of held.data as unknown as StageRow[]) {
    facts.set(row.unit_name.toLowerCase(), row);
  }

  /** What one stage builds, lowercased and deduplicated, from whichever record
   *  the page is showing. */
  const optionsOf = (stage: MorphStage): Set<string> => {
    const row = facts.get(stage.name);
    const reported = version ? row?.game_unit_revision?.[0]?.build_options : row?.build_options;
    return new Set((reported ?? []).map((option) => option?.toLowerCase()).filter(Boolean));
  };

  // Every name any stage builds, labelled in one read. A build option nobody
  // holds keeps its own key, the same as the page's own Builds list does:
  // dropping it would hide exactly the edge a reader came to check.
  const unlocked = [
    ...new Set(group.stages.flatMap((stage) => [...optionsOf(stage)])),
  ].sort();
  const labels = new Map<string, string>();
  if (unlocked.length > 0) {
    const { data } = await supabase
      .from("game_unit")
      .select("unit_name,full_name,game!inner(shortname)")
      .eq("game.shortname", shortname)
      .in("unit_name", unlocked);
    for (const row of (data ?? []) as unknown as { unit_name: string; full_name: string | null }[]) {
      labels.set(row.unit_name.toLowerCase(), row.full_name ?? row.unit_name);
    }
  }

  const unlocksOf = (stage: MorphStage, previous: MorphStage | null) => {
    if (!previous) return [];
    const before = optionsOf(previous);
    return [...optionsOf(stage)]
      .filter((option) => !before.has(option))
      .sort()
      .map((option) => ({ name: option, label: labels.get(option) ?? option }));
  };

  const stages: UnitStage[] = group.stages.map((stage) => {
    const row = facts.get(stage.name);
    const revision = version ? (row?.game_unit_revision?.[0] ?? null) : null;
    const found = version ? revision !== null : row !== undefined;
    // What the stage before this one could build. A stage nothing leads to -
    // the base, or the far root of a converging group - unlocks nothing rather
    // than claiming its whole build list is new, which the page's own Builds
    // section already shows.
    const previous = stage.from
      ? (group.stages.find((candidate) => candidate.name === stage.from) ?? null)
      : null;

    return {
      unit_name: stage.unit_name,
      // The same fallback the page's own heading takes: a release that named
      // nothing falls back to the def key, not to today's name.
      label: (version ? revision?.full_name : row?.full_name) ?? stage.unit_name,
      current: stage.name === key,
      from: stage.from ? (facts.get(stage.from)?.unit_name ?? stage.from) : null,
      conditions: stage.conditions,
      unlocks: unlocksOf(stage, previous),
      stats: (version ? revision?.stats : row?.stats) ?? {},
      found,
      removed_at: row?.removed_at ?? null,
    };
  });

  // The union of every stage's stat keys, in reading order, so a stat one
  // level introduces is on the table with the levels below it reading as not
  // recorded. The same rule the release comparison follows.
  const ordered = stages
    .flatMap((stage) => statRows(stage.stats).map((row) => row.key))
    .filter((key, index, all) => all.indexOf(key) === index)
    // A stat whose value is a list of records draws as its own table (#261),
    // and `formatStatValue` would print it as a line of JSON in a column two
    // inches wide. A weapons summary belongs on the stage's own page, which
    // the strip above the table links to, rather than mangled here.
    .filter((key) => stages.every((stage) => tabularStatRows(stage.stats[key]) === null));

  const stage_stats: StageStatRow[] = ordered.map((key) => {
    const values = stages.map((stage) => formatStatValue(stage.stats[key] ?? null));
    return {
      key,
      label: statLabel(key),
      values,
      changed: new Set(values).size > 1,
    };
  });

  return { stages, stage_stats };
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

/**
 * Whether a side is the die roll rather than an army (#280).
 *
 * Games report a faction called Random so a lobby can pick one for you. It has
 * no units of its own worth a strip, a filter or a tree block, and offering it
 * beside real sides reads as a side somebody plays.
 */
function isRandomFaction(faction: { key: string; name: string }): boolean {
  return (
    faction.key.trim().toLowerCase() === "random" ||
    faction.name.trim().toLowerCase() === "random"
  );
}

export async function gameFactions(
  supabase: SupabaseClient,
  shortname: string,
): Promise<FactionOption[]> {
  const { data } = await supabase
    .from("game_faction")
    .select("key,name,game!inner(shortname)")
    .eq("game.shortname", shortname);
  return ((data ?? []) as unknown as { key: string; name: string }[])
    .filter((f) => !isRandomFaction(f))
    .map((f) => ({
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

/** What the catalog calls a unit, as the encyclopedia links to it. */
export interface UnitNameLabel {
  /** The unit's name as the catalog spells it, which is what its page's URL
   *  segment has to be: the unit page reads by exact match. */
  name: string;
  label: string;
}

/**
 * Every unit of a game the catalog holds, keyed on the lower cased def.
 *
 * A blueprint's roster names buildings with whatever the author typed, lower
 * cased on read (`lib/gallery/blueprintPreview.ts`), while the catalog stores a
 * row under each unit's authored name. Reading the whole game's names once and
 * keying them lower cased makes that join case-insensitive by construction, the
 * same way `buildTree` keys its walk, rather than by everybody remembering to
 * normalise at every use.
 */
export async function unitNameLabels(
  supabase: SupabaseClient,
  shortname: string,
): Promise<ReadonlyMap<string, UnitNameLabel>> {
  // Paged, because a name this misses is a blueprint entry that loses its link
  // rather than an error anybody sees.
  const data = await readAll<{ unit_name: string; full_name: string | null }>((from, to) =>
    supabase
      .from("game_unit")
      .select("unit_name,full_name,game!inner(shortname)")
      .eq("game.shortname", shortname)
      .order("unit_name")
      .range(from, to),
  );

  const names = new Map<string, UnitNameLabel>();
  for (const row of data ?? []) {
    names.set(row.unit_name.toLowerCase(), {
      name: row.unit_name,
      label: row.full_name ?? row.unit_name,
    });
  }
  return names;
}

/**
 * Who builds this unit: the units holding it as a build option. The edge lives
 * on the builder's row and not on the unit's, so this is a containment read
 * across the game's units rather than anything the unit itself carries.
 *
 * `version` answers for one release, riding the same inner joined revision
 * `loadTree` reads, so an old page's two directions agree with each other and
 * name a builder what that release named it. Without one the answer is current
 * facts, and a retired builder does not count: the build option a retired unit
 * holds is not a live path, which is the rule {@link unbuildableUnits} decides
 * ghosts by, and a unit hidden from the grid as a ghost must not claim a
 * builder on its own page.
 *
 * Matched exact case, as the forward direction's `in` on `unit_name` is, so the
 * two break together rather than disagreeing about one edge. Sorted, because
 * the read's order is not a fact, and empty on a failed read rather than taking
 * the page down with it.
 */
export async function unitBuilders(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
  version?: string | null,
): Promise<{ name: string; label: string }[]> {
  const { data, error } = version
    ? await supabase
        .from("game_unit")
        .select(
          "unit_name,full_name,game!inner(shortname)," +
            "game_unit_revision!inner(full_name,build_options)",
        )
        .eq("game.shortname", shortname)
        .eq("game_unit_revision.version", version)
        .contains("game_unit_revision.build_options", [unitName])
    : await supabase
        .from("game_unit")
        .select("unit_name,full_name,game!inner(shortname)")
        .eq("game.shortname", shortname)
        .contains("build_options", [unitName])
        // `is`, not `eq`: PostgREST refuses `removed_at=eq.null` (#255).
        .is("removed_at", null);

  if (error || !data) return [];

  const rows = data as unknown as {
    unit_name: string;
    full_name: string | null;
    game_unit_revision?: { full_name: string | null }[];
  }[];

  return rows
    .map((row) => {
      // The same fallback the unit's own heading takes: a release that named
      // nothing falls back to the def key, not to today's name.
      const named = version ? (row.game_unit_revision?.[0]?.full_name ?? null) : row.full_name;
      return { name: row.unit_name, label: named ?? row.unit_name };
    })
    .sort((a, b) => a.name.localeCompare(b.name));
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
  /** What builds this unit, the reverse of {@link UnitPage.builds}. */
  built_by: { name: string; label: string }[];
  /** The stages of this unit's life, in order from the base (#295). Empty when
   *  it turns into nothing and nothing turns into it, which is most units. */
  stages: UnitStage[];
  /** Every stat any stage carries, one column per stage. Empty alongside
   *  {@link UnitPage.stages}. */
  stage_stats: StageStatRow[];
}

export async function loadUnitPage(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
  version?: string,
): Promise<UnitPage | null> {
  // The revision the reader asked for, narrowed by the server rather than
  // searched for in whatever the embed happened to carry. This used to order
  // the revisions newest first and take one, then look through that one for the
  // release asked for: an older release was never in it, so every `?v=` older
  // than the newest showed current facts under that release's name and said the
  // hub held no record.
  //
  // Filtered, not inner joined, because a release with no revision for this
  // unit still has to render. The parent row survives with an empty embed, and
  // the page says the record is missing.
  //
  // No version asked for means no embed at all, since nothing reads it then.
  const asked = version ?? null;
  const [held, versions, factions] = await Promise.all([
    (asked
      ? supabase
          .from("game_unit")
          .select(
            "id,unit_name,full_name,faction_key,build_options,stats,snippet,source_version,removed_at," +
              "game!inner(shortname)," +
              "game_unit_revision(version,full_name,faction_key,build_options,stats)",
          )
          .eq("game_unit_revision.version", asked)
      : supabase
          .from("game_unit")
          .select(
            "id,unit_name,full_name,faction_key,build_options,stats,snippet,source_version,removed_at," +
              "game!inner(shortname)",
          )
    )
      .eq("game.shortname", shortname)
      .eq("unit_name", unitName)
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
  const revision = asked ? (row.game_unit_revision?.[0] ?? null) : null;

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
  // Both directions of the edge, read together: which release they answer for
  // is only known once the revision above has been resolved, so neither could
  // join the batch that read it.
  const [builds, builtBy, staged] = await Promise.all([
    options.length === 0
      ? []
      : supabase
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
          }),
    unitBuilders(supabase, shortname, row.unit_name, revision ? asked : null),
    loadUnitStages(supabase, shortname, row.unit_name, revision ? asked : null),
  ]);

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
    built_by: builtBy,
    stages: staged.stages,
    stage_stats: staged.stage_stats,
  };
}

/** One angle of a unit, as the page draws it. The angle rides along because
 *  `asset.served` carries a variant and a caption wants the angle on its own. */
export interface UnitRenderView {
  angle: string;
  asset: ResolvedAsset;
}

/**
 * Every angle of a unit, resolved through the ladder `lib/assets/resolve.ts`
 * owns, in one read.
 *
 * One read rather than one per angle: `fetchHeldAssets` takes the whole set and
 * chunks it, so four angles and the buildpic behind them are a single query.
 * Asking angle by angle would be four round trips for a page that draws them
 * together.
 *
 * Every angle comes back, held or not, and the caller drops what it cannot
 * draw. Returning only what is stored would hide the ladder's substitution from
 * a caller that has to know about it: a missing angle resolves to the buildpic,
 * which the portrait is already showing.
 */
export async function unitRenders(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
): Promise<UnitRenderView[]> {
  const identities: AssetIdentity[] = UNIT_RENDER_ANGLES.map((angle) => ({
    keyedOn: "unit",
    game: shortname,
    unitName,
    variant: `${UNIT_RENDER_VARIANT_PREFIX}${angle}`,
  }));
  const held = await fetchHeldAssets(supabase, identities);

  return UNIT_RENDER_ANGLES.map((angle, index) => ({
    angle,
    asset: resolveAsset(identities[index], held, null),
  }));
}

/**
 * The buildpic on its own terms, beside the render rather than under it (#259).
 *
 * The render's own resolution substitutes a buildpic when no top down render
 * is stored, which is the ladder working; this read is what lets a page show
 * both pictures at once when the hub holds both.
 */
export async function unitBuildpic(
  supabase: SupabaseClient,
  shortname: string,
  unitName: string,
): Promise<ResolvedAsset> {
  const identity: AssetIdentity = {
    keyedOn: "unit",
    game: shortname,
    unitName,
    variant: UNIT_BUILDPIC_VARIANT,
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
