import type { SupabaseClient } from "@supabase/supabase-js";
import { UNIT_BUILDPIC_VARIANT, type AssetIdentity } from "@/lib/assets/asset";
import { fetchHeldAssets, resolveAsset, type HeldAssets, type ServedAsset } from "@/lib/assets/resolve";
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
 * catalog cannot place, or one with no buildpic held, draws nothing rather than
 * a placeholder, because a row of drawn boxes would promise pictures nobody has.
 */

export interface SideCommander {
  unit_name: string;
  /** What the catalog calls the unit, or its def name when it says nothing. */
  label: string;
  picture: ServedAsset;
}

export interface GameSides {
  /** Alphabetical, Random left out, as the game's page lists them. */
  factions: { key: string; name: string }[];
  /** Keyed by faction key. A side with no placeable, pictured start unit is
   *  absent. */
  commanders: ReadonlyMap<string, SideCommander>;
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

function buildpicIdentity(game: string, unitName: string): AssetIdentity {
  return { keyedOn: "unit", game, unitName, variant: UNIT_BUILDPIC_VARIANT };
}

/** The rows and the held pictures into one answer per game. Apart from the
 *  reads so a test can hand it rows. */
export function assembleSides(
  games: GameRow[],
  units: UnitRow[],
  held: HeldAssets,
): Map<string, GameSides> {
  const sides = new Map<string, GameSides>();
  for (const game of games) {
    const factions = (game.game_faction ?? [])
      .filter((faction) => !isRandomFaction(faction))
      .map((faction) => ({ key: faction.key, name: faction.name }))
      .sort((left, right) => left.name.localeCompare(right.name));

    const commanders = new Map<string, SideCommander>();
    for (const unitName of game.start_units ?? []) {
      const unit = units.find(
        (row) => row.game.shortname === game.shortname && row.unit_name === unitName,
      );
      if (!unit?.faction_key || commanders.has(unit.faction_key)) continue;
      if (!factions.some((faction) => faction.key === unit.faction_key)) continue;

      const picture = resolveAsset(buildpicIdentity(game.shortname, unitName), held);
      if (picture.from === "placeholder") continue;
      commanders.set(unit.faction_key, {
        unit_name: unitName,
        label: unit.full_name ?? unitName,
        picture,
      });
    }

    sides.set(game.shortname, { factions, commanders });
  }
  return sides;
}

/**
 * The sides of every named game. A game that failed to read is absent from the
 * answer, and a page draws its card or header without sides rather than
 * failing, because the sides dress the page and are not what it is for.
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
  if (startUnits.length === 0) return assembleSides(games, [], new Map());

  const unitRead = await supabase
    .from("game_unit")
    .select("unit_name,full_name,faction_key,game!inner(shortname)")
    .in("game.shortname", shortnames)
    .in("unit_name", startUnits)
    .is("removed_at", null);
  const units = unitRead.error ? [] : ((unitRead.data ?? []) as unknown as UnitRow[]);

  const held = await fetchHeldAssets(
    supabase,
    units.map((unit) => buildpicIdentity(unit.game.shortname, unit.unit_name)),
  );
  return assembleSides(games, units, held);
}
