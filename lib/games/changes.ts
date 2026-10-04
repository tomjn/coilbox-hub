import type { SupabaseClient } from "@supabase/supabase-js";
import { readAll } from "@/lib/supabase/readAll";
import { formatStatValue, statLabel, statRows, statValueChanged } from "./stats";

/**
 * What changed between two releases of a game.
 *
 * ## What a release holds
 *
 * `submit_game_facts` writes one `game_unit_revision` for every unit a
 * submission names, whether or not its facts moved since the last release (the
 * insert is `on conflict (unit_id, version) do nothing`, and an unchanged unit
 * is answered "unchanged" with its revision for the new release already
 * stored). So a complete report leaves a revision for every unit the release
 * shipped, and a unit with no revision at a release was not in that release's
 * report. It is not "unchanged since an earlier one".
 *
 * That makes the state of a unit at a release the revision keyed by exactly
 * that release, which is how the unit compare page resolves it
 * (`loadUnitComparison`). Added is a revision in the newer release and none in
 * the older. Removed is the reverse. `game_unit.removed_at` is not consulted: it
 * is one timestamp for today's state and says nothing about any release.
 *
 * The weak spot is a partial submission, which names only some units and
 * retires none. A release reported that way holds revisions for only those
 * units, and this reads the rest as missing from it.
 */

/** One unit as a release stored it. */
export interface ReleaseUnit {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
}

/** A unit named in the added and removed lists. */
export interface UnitRef {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
}

export interface StatChange {
  key: string;
  label: string;
  /** Both sides as the compare page prints them: a dash for absent. */
  from: string;
  to: string;
}

export interface UnitChange extends UnitRef {
  /** Set when the display name changed. */
  renamed: { from: string | null; to: string | null } | null;
  /** Set when the unit moved to another faction. `faction_key` is the newer one. */
  faction: { from: string | null; to: string | null } | null;
  stats: StatChange[];
  /** Lower cased def names, sorted. */
  builds: { gained: string[]; lost: string[] };
}

export interface ReleaseChanges {
  added: UnitRef[];
  removed: UnitRef[];
  changed: UnitChange[];
  /** Units both releases hold with nothing different. */
  unchanged: number;
}

/** Lower cased, deduplicated and sorted. The array order of a unit's build
 *  options is not a fact (the unit page and the tree both sort them), and the
 *  tree matches them case insensitively. */
function optionSet(options: string[] | null): string[] {
  return [...new Set((options ?? []).map((option) => option?.toLowerCase()).filter(Boolean))].sort();
}

function ref(unit: ReleaseUnit): UnitRef {
  return { unit_name: unit.unit_name, full_name: unit.full_name, faction_key: unit.faction_key };
}

function diffUnit(from: ReleaseUnit, to: ReleaseUnit): UnitChange | null {
  const stats: StatChange[] = [
    ...statRows(from.stats ?? {}).map((row) => row.key),
    ...statRows(to.stats ?? {}).map((row) => row.key),
  ]
    .filter((key, index, all) => all.indexOf(key) === index)
    .filter((key) => statValueChanged(from.stats?.[key], to.stats?.[key]))
    .map((key) => ({
      key,
      label: statLabel(key),
      from: formatStatValue(from.stats?.[key] ?? null),
      to: formatStatValue(to.stats?.[key] ?? null),
    }));

  const before = new Set(optionSet(from.build_options));
  const after = new Set(optionSet(to.build_options));
  const builds = {
    gained: [...after].filter((option) => !before.has(option)),
    lost: [...before].filter((option) => !after.has(option)),
  };

  const renamed = from.full_name !== to.full_name ? { from: from.full_name, to: to.full_name } : null;
  const faction =
    from.faction_key !== to.faction_key ? { from: from.faction_key, to: to.faction_key } : null;

  if (stats.length === 0 && builds.gained.length === 0 && builds.lost.length === 0 && !renamed && !faction) {
    return null;
  }
  return { ...ref(to), renamed, faction, stats, builds };
}

/**
 * The difference between two releases, each as the units its revisions hold.
 * Lists come back unsorted. Callers order them for display.
 */
export function diffReleases(from: ReleaseUnit[], to: ReleaseUnit[]): ReleaseChanges {
  const older = new Map(from.map((unit) => [unit.unit_name, unit]));
  const newer = new Map(to.map((unit) => [unit.unit_name, unit]));

  const added: UnitRef[] = [];
  const changed: UnitChange[] = [];
  let unchanged = 0;
  for (const [name, unit] of newer) {
    const before = older.get(name);
    if (!before) {
      added.push(ref(unit));
      continue;
    }
    const change = diffUnit(before, unit);
    if (change) changed.push(change);
    else unchanged += 1;
  }

  const removed = [...older.values()].filter((unit) => !newer.has(unit.unit_name)).map(ref);
  return { added, removed, changed, unchanged };
}

/** Units under one heading. */
export interface FactionGroup<T extends UnitRef> {
  key: string | null;
  name: string;
  units: T[];
}

/**
 * Units grouped by faction, in the order the game lists its sides, with a last
 * group for units whose faction is empty or no longer listed. Inside a group
 * units read by display name, falling back to the def name.
 */
export function groupByFaction<T extends UnitRef>(
  units: T[],
  factions: { key: string; name: string }[],
): FactionGroup<T>[] {
  const label = (unit: T) => unit.full_name ?? unit.unit_name;
  const sorted = [...units].sort(
    (a, b) => label(a).localeCompare(label(b)) || a.unit_name.localeCompare(b.unit_name),
  );

  const groups: FactionGroup<T>[] = factions.map((faction) => ({
    key: faction.key,
    name: faction.name,
    units: sorted.filter((unit) => unit.faction_key === faction.key),
  }));
  const known = new Set(factions.map((faction) => faction.key));
  groups.push({
    key: null,
    name: "No faction",
    units: sorted.filter((unit) => !unit.faction_key || !known.has(unit.faction_key)),
  });
  return groups.filter((group) => group.units.length > 0);
}

/**
 * The pair of releases the page shows.
 *
 * `releases` is newest first. A name the hub does not hold falls back to the
 * default for its side and earns a note. A pair the reader chose is kept as
 * chosen even when `from` is the newer. The sections are named for the two
 * releases they compare, and which is newer is not a fact the hub records
 * reliably, since a release's `last_seen_at` moves on every report.
 */
export function resolveReleasePair(
  releases: string[],
  askedFrom: string | undefined,
  askedTo: string | undefined,
): { from: string | null; to: string | null; notes: string[] } {
  if (releases.length < 2) return { from: null, to: null, notes: [] };

  const held = new Set(releases);
  const notes: string[] = [];
  const known = (asked: string | undefined) => {
    if (asked === undefined || asked === "") return null;
    if (held.has(asked)) return asked;
    notes.push(`The hub holds no release called "${asked}".`);
    return null;
  };
  const from = known(askedFrom);
  const to = known(askedTo);

  // A side left to default takes the neighbour of the other side, so one good
  // name beside a bad one does not collapse into the same release twice.
  const beside = (release: string, step: number) => {
    const at = releases.indexOf(release);
    return releases[at + step] ?? releases[at - step];
  };
  return {
    from: from ?? (to ? beside(to, 1) : releases[1]),
    to: to ?? (from ? beside(from, -1) : releases[0]),
    notes,
  };
}

/** Every release a game has, newest report first, as the unit page's picker
 *  orders them. Null on a failed read. */
export async function loadGameReleases(
  supabase: SupabaseClient,
  shortname: string,
): Promise<{ version: string; last_seen_at: string }[] | null> {
  const { data, error } = await supabase
    .from("game_version")
    .select("version,last_seen_at,game!inner(shortname)")
    .eq("game.shortname", shortname)
    .order("last_seen_at", { ascending: false });
  if (error || !data) return null;
  return (data as unknown as { version: string; last_seen_at: string }[]).map((row) => ({
    version: row.version,
    last_seen_at: row.last_seen_at,
  }));
}

interface RevisionRow extends Omit<ReleaseUnit, "unit_name"> {
  version: string;
  game_unit: { unit_name: string };
}

/**
 * The difference between two releases of one game.
 *
 * Both releases' revisions come in one paged read, filtered to the game and the
 * two versions, and are split by version here. A game has hundreds of units, so
 * a read per unit is not an option, and `readAll` is what keeps a game past the
 * response cap from being read short. Null on a failed read.
 */
export async function loadReleaseChanges(
  supabase: SupabaseClient,
  shortname: string,
  from: string,
  to: string,
): Promise<ReleaseChanges | null> {
  const rows = await readAll<RevisionRow>((start, end) =>
    supabase
      .from("game_unit_revision")
      .select(
        "id,version,full_name,faction_key,build_options,stats,game_unit!inner(unit_name,game!inner(shortname))",
      )
      .eq("game_unit.game.shortname", shortname)
      .in("version", [...new Set([from, to])])
      .order("id")
      .range(start, end),
  );
  if (!rows) return null;

  const side = (version: string): ReleaseUnit[] =>
    rows
      .filter((row) => row.version === version)
      .map((row) => ({
        unit_name: row.game_unit.unit_name,
        full_name: row.full_name,
        faction_key: row.faction_key,
        build_options: row.build_options ?? [],
        stats: row.stats ?? {},
      }));
  return diffReleases(side(from), side(to));
}
