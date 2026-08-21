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
  const factions = game.faction_count === 1 ? "1 faction" : `${game.faction_count} factions`;
  return `${factions}, ${game.unit_count} units`;
}
