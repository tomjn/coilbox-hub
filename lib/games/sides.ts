import type { SupabaseClient } from "@supabase/supabase-js";
import { UNIT_BUILDPIC_VARIANT, type AssetIdentity } from "@/lib/assets/asset";
import { fetchHeldAssets, resolveAsset, type HeldAssets, type ResolvedAsset } from "@/lib/assets/resolve";
import { isRandomFaction } from "./factions";

/**
 * Each game's sides, and the unit a player starts each one with.
 *
 * A game's logo says which game it is. The start units say what it is: a BAR
 * commander, a Spring 1944 army HQ, a Zero-K nothing yet. The hub already holds
 * their buildpics for most games, so the listing card and the game's page draw
 * them rather than a tag somebody would have to write.
 *
 * A start unit belongs to a side through `game_unit.faction_key`. One the
 * catalog cannot place on a side the game lists is not drawn, because there is
 * no side to draw it against.
 *
 * ## A missing buildpic is still a unit
 *
 * A start unit the hub holds no picture for used to be dropped. That put the
 * unit's name, its side and the fact that it is the one a player starts with
 * behind whether a picture had been uploaded, and a game part way through its
 * seeding lost half its row for it. The picture now falls through the ladder in
 * `lib/assets/resolve.ts` to the placeholder, the way every other unit picture
 * on the site does, and the unit is drawn either way.
 *
 * ## A game that has never said
 *
 * `start_units` is null until a client reports it, and plenty of games have not.
 * Their card drew nothing at all. It now draws three units picked at random out
 * of the game's own catalog, through `public.game_sample_units`. They are not
 * the units a player starts with and nothing claims they are: they are there so
 * a reader sees what the game's units look like instead of a gap.
 */

/** How many units stand in for a game that has named no start units. Three is
 *  what the narrowest card has room for beside its counts. */
const SAMPLE_SIZE = 3;

export interface SideCommander {
  unit_name: string;
  /** What the catalog calls the unit, or its def name when it says nothing. */
  label: string;
  picture: ResolvedAsset;
}

export interface GameSides {
  /** Alphabetical, Random left out, as the game's page lists them. */
  factions: { key: string; name: string }[];
  /** Keyed by faction key. A side with no placeable start unit is absent. */
  commanders: ReadonlyMap<string, SideCommander>;
  /**
   * Units of this game picked at random, for a game whose start units nobody
   * has reported. Empty whenever {@link commanders} holds anything, so a caller
   * draws one or the other and never both.
   */
  sample: SideCommander[];
}

interface GameRow {
  shortname: string;
  start_units: string[] | null;
  game_faction: { key: string; name: string }[] | null;
}

interface UnitRow {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  game: { shortname: string };
}

/** A unit off `game_sample_units`, which knows nothing about sides. */
export interface SampledUnit {
  unit_name: string;
  full_name: string | null;
}

function buildpicIdentity(game: string, unitName: string): AssetIdentity {
  return { keyedOn: "unit", game, unitName, variant: UNIT_BUILDPIC_VARIANT };
}

function factionsOf(game: GameRow): { key: string; name: string }[] {
  return (game.game_faction ?? [])
    .filter((faction) => !isRandomFaction(faction))
    .map((faction) => ({ key: faction.key, name: faction.name }))
    .sort((left, right) => left.name.localeCompare(right.name));
}

/** Which side each start unit belongs to, before any picture is looked up. The
 *  one definition of "this game has start units worth drawing", so the loader
 *  and the assembler cannot disagree about which games need a sample. */
function placeStartUnits(
  game: GameRow,
  factions: { key: string }[],
  units: UnitRow[],
): Map<string, UnitRow> {
  const placed = new Map<string, UnitRow>();
  for (const unitName of game.start_units ?? []) {
    const unit = units.find(
      (row) => row.game.shortname === game.shortname && row.unit_name === unitName,
    );
    if (!unit?.faction_key || placed.has(unit.faction_key)) continue;
    if (!factions.some((faction) => faction.key === unit.faction_key)) continue;
    placed.set(unit.faction_key, unit);
  }
  return placed;
}

function drawn(game: string, unit: SampledUnit, held: HeldAssets): SideCommander {
  return {
    unit_name: unit.unit_name,
    label: unit.full_name ?? unit.unit_name,
    picture: resolveAsset(buildpicIdentity(game, unit.unit_name), held),
  };
}

/** The games nobody has named a placeable start unit for, which are the ones a
 *  random sample stands in for. */
export function gamesNeedingSample(games: GameRow[], units: UnitRow[]): string[] {
  return games
    .filter((game) => placeStartUnits(game, factionsOf(game), units).size === 0)
    .map((game) => game.shortname);
}

/** The rows and the held pictures into one answer per game. Apart from the
 *  reads so a test can hand it rows. */
export function assembleSides(
  games: GameRow[],
  units: UnitRow[],
  held: HeldAssets,
  sampled: ReadonlyMap<string, SampledUnit[]> = new Map(),
): Map<string, GameSides> {
  const sides = new Map<string, GameSides>();
  for (const game of games) {
    const factions = factionsOf(game);

    const commanders = new Map<string, SideCommander>();
    for (const [key, unit] of placeStartUnits(game, factions, units)) {
      commanders.set(key, drawn(game.shortname, unit, held));
    }

    const sample =
      commanders.size > 0
        ? []
        : (sampled.get(game.shortname) ?? []).map((unit) => drawn(game.shortname, unit, held));

    sides.set(game.shortname, { factions, commanders, sample });
  }
  return sides;
}

/** A few units of each named game, at random. An empty map when nothing is
 *  asked for or the call fails: a sample is what stands in for missing facts,
 *  so failing to get one leaves the card as it was rather than the page. */
async function sampleUnits(
  supabase: SupabaseClient,
  shortnames: string[],
): Promise<Map<string, SampledUnit[]>> {
  const sampled = new Map<string, SampledUnit[]>();
  if (shortnames.length === 0) return sampled;

  const { data, error } = await supabase.rpc("game_sample_units", {
    p_shortnames: shortnames,
    p_count: SAMPLE_SIZE,
  });
  if (error || !data) return sampled;

  for (const row of data as (SampledUnit & { shortname: string })[]) {
    const units = sampled.get(row.shortname) ?? [];
    units.push({ unit_name: row.unit_name, full_name: row.full_name });
    sampled.set(row.shortname, units);
  }
  return sampled;
}

/**
 * The sides of every named game. A game that failed to read is absent from the
 * answer, and a page draws its card or header without sides rather than
 * failing, because the sides dress the page and are not what it is for.
 *
 * Four reads at most, in order, because each one needs the last: the games, the
 * units their start lists name, a sample for whichever games that left with
 * nothing, and then one batch of pictures covering both sets of units.
 */
export async function loadGameSides(
  supabase: SupabaseClient,
  shortnames: string[],
): Promise<Map<string, GameSides>> {
  if (shortnames.length === 0) return new Map();

  const gameRead = await supabase
    .from("game")
    .select("shortname,start_units,game_faction(key,name)")
    .in("shortname", shortnames);
  if (gameRead.error || !gameRead.data) return new Map();
  const games = gameRead.data as unknown as GameRow[];

  const startUnits = [...new Set(games.flatMap((game) => game.start_units ?? []))];
  let units: UnitRow[] = [];
  if (startUnits.length > 0) {
    const unitRead = await supabase
      .from("game_unit")
      .select("unit_name,full_name,faction_key,game!inner(shortname)")
      .in("game.shortname", shortnames)
      .in("unit_name", startUnits)
      .is("removed_at", null);
    units = unitRead.error ? [] : ((unitRead.data ?? []) as unknown as UnitRow[]);
  }

  const sampled = await sampleUnits(supabase, gamesNeedingSample(games, units));

  const held = await fetchHeldAssets(supabase, [
    ...units.map((unit) => buildpicIdentity(unit.game.shortname, unit.unit_name)),
    ...[...sampled].flatMap(([shortname, sample]) =>
      sample.map((unit) => buildpicIdentity(shortname, unit.unit_name)),
    ),
  ]);
  return assembleSides(games, units, held, sampled);
}
