/**
 * Whether a side is the die roll rather than an army (#280).
 *
 * Games report a faction called Random so a lobby can pick one for you. It has
 * no units of its own worth a strip, a filter or a tree block, and offering it
 * beside real sides reads as a side somebody plays. The games listing view
 * leaves it out of its faction count by the same rule
 * (20260914220000_game_browse_random_faction.sql), so the count and the sides a
 * page draws agree.
 */
export function isRandomFaction(faction: { key: string; name: string }): boolean {
  return (
    faction.key.trim().toLowerCase() === "random" ||
    faction.name.trim().toLowerCase() === "random"
  );
}
