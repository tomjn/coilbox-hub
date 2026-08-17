/**
 * Pure logic behind the setup pack contents in
 * `components/SetupPackContents.tsx`, split out so it can be unit tested
 * without a rendering library.
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

/** The maps a pack installs, in the order it lists them. Blanks are dropped,
 * because a name is what a map is looked up and pictured by. */
export function setupPackMapNames(payload: Record<string, unknown>): string[] {
  const maps = Array.isArray(payload.maps) ? payload.maps : [];
  return maps.filter(
    (name): name is string => typeof name === "string" && name !== "",
  );
}

/**
 * The maps a whole container installs, once each and in the order the pack
 * lists them, for the callers that hold an item rather than a payload: the page
 * looks each one up in BAR's list and `lib/gallery/itemPictures.ts` asks for a
 * picture of each. Empty for anything that is not a pack, or a pack whose
 * payload is not an object.
 */
export function setupPackMaps(container: unknown): string[] {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (typeof payload !== "object" || payload === null) return [];
  return [...new Set(setupPackMapNames(payload as Record<string, unknown>))];
}

/**
 * The engine build a pack pins, or null for one that pins none.
 *
 * `.spring` is the launcher's own word for "no version", so it is an absence
 * here too. A pack that pins nothing shows no engine at all (issue #176): the
 * heading and the line it would need are worth more to a pack that says
 * something than the reassurance that this one does not.
 */
export function setupPackEngine(payload: Record<string, unknown>): string | null {
  const engine = payload.engineVersion;
  if (typeof engine !== "string" || engine === "" || engine === ".spring") {
    return null;
  }
  return engine;
}
