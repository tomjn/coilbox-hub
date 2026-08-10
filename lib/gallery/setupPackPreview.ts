/**
 * Pure logic behind the setup pack preview in `components/ItemPreview.tsx`,
 * split out so it can be unit tested without a rendering library.
 *
 * A pack was a snapshot of one player's setup and named the one `game` it came
 * from. It is now an arbitrary collection of content to install ("popular water
 * maps"), so it names `games`, an array of the same shape. Both spellings are
 * read: packs published before the change carry the old one and their preview
 * has to keep working.
 */

/** The archive names of a pack's games, in the order the pack lists them.
 *
 * Only `name` is printed, because the row says which builds a pack installs and
 * a shortname is not one. An entry carrying only a shortname (a
 * `GameIdentity` pins no build, see `lib/container/gameIdentity.ts`) therefore
 * has nothing to show and is dropped rather than rendered blank. */
export function setupPackGameNames(payload: Record<string, unknown>): string[] {
  const games = Array.isArray(payload.games)
    ? payload.games
    : [payload.game];
  return games
    .map((game) =>
      typeof game === "object" && game !== null
        ? (game as { name?: unknown }).name
        : undefined,
    )
    .filter((name): name is string => typeof name === "string" && name !== "");
}
