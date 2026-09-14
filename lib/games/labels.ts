import type { GameSummary } from "./query";

/**
 * What a game's row says, in the words a page prints (#225).
 *
 * `lib/maps/labels.ts` sets out why these live here rather than in the pages
 * that print them: the listing and the game's own page are two views of one row,
 * and sentences invented twice drift the first time somebody edits one.
 */

/** The name a reader sees. A backfilled game is a shortname alone until
 * anybody has written a display name, and BA is a perfectly good thing for a
 * heading to say meanwhile. */
export function gameTitle(game: Pick<GameSummary, "shortname" | "display_name">): string {
  return game.display_name ?? game.shortname;
}

/**
 * The counts as one sentence, in the words a player uses.
 *
 * "2 factions, 340 units" is what the numbers mean and nothing more. Zero is
 * printed as zero rather than hidden: a game with no units reported yet is
 * exactly what a visitor is looking at, and pretending otherwise would read as
 * a broken counter once the facts arrive.
 */
export function gameCountLabel(game: Pick<GameSummary, "faction_count" | "unit_count">): string {
  return gameCountParts(game)
    .map((part) => `${part.count} ${part.noun}`)
    .join(", ");
}

/**
 * The same counts, number and noun apart, for a card that sets the numbers
 * brighter than the words. Built here so the singular stays in one place.
 */
export function gameCountParts(
  game: Pick<GameSummary, "faction_count" | "unit_count">,
): { count: number; noun: string }[] {
  return [
    { count: game.faction_count, noun: game.faction_count === 1 ? "faction" : "factions" },
    { count: game.unit_count, noun: "units" },
  ];
}

/**
 * Whether a description says anything beyond the game's name.
 *
 * Some games describe themselves with their own shortname and nothing else
 * ("BOTA"). Printed under the title, that reads as a placeholder, so a page
 * treats it as no description. Compared as plain text, case and spacing
 * ignored.
 */
export function saysMoreThanName(
  game: Pick<GameSummary, "shortname" | "display_name">,
  plainDescription: string | null,
): boolean {
  const said = plainDescription?.trim().toLowerCase() ?? "";
  if (said === "") return false;
  const names = [game.shortname, game.display_name]
    .filter((name): name is string => name !== null)
    .map((name) => name.trim().toLowerCase());
  return !names.includes(said);
}

/**
 * The sides a player can pick, as a sentence, for a card with nothing else to
 * say: "Play as ARM or CORE." Null for a game with no sides reported.
 */
export function playAsLabel(factionNames: string[]): string | null {
  if (factionNames.length === 0) return null;
  const list = new Intl.ListFormat("en", { type: "disjunction" }).format(factionNames);
  return `Play as ${list}.`;
}

/**
 * What the community card says under its name. A count when there is
 * something to count, and a plain statement when there is not, because "0
 * community items" under a link reads as a dead end.
 */
export function itemCardLabel(count: number): string {
  return count === 0 ? "Nothing published yet" : itemCountLabel(count);
}

/**
 * Community content as a count, singular handled.
 *
 * "Community items" rather than naming kinds, because the gallery's list of
 * kinds grows on its own side and a sentence naming two of them goes stale at
 * the third.
 */
export function itemCountLabel(count: number): string {
  return count === 1 ? "1 community item" : `${count} community items`;
}
