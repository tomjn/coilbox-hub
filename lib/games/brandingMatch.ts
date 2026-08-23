/**
 * Which branding catalog entry brands a game.
 *
 * The matching rule is coilbox's own (`src/content/branding.ts`), because the
 * question a hub page asks is the one the app answers: which catalog entry did
 * the player's own install show? Two answers to that would make the hub brand a
 * game differently from the launcher beside it.
 *
 * This file restates `entryMatches`, `compile` and `resolveBranding` rather
 * than vendoring them, because upstream's module reaches React and the plugin
 * bindings and so cannot be vendored whole. The restatement is pinned by tests
 * against the real vendored catalog (`lib/games/vendor/catalog.json`), which is
 * the same drift guard in a different key: an upstream change to the rule or
 * the entries shows up as a failing test here rather than as silent
 * disagreement. If upstream ever extracts a plain module, vendor it and delete
 * this file.
 */

/** How an entry says which games it brands. */
export interface BrandingMatch {
  /** Case-insensitive regex tested against the game's name. */
  regex?: string;
  /** Case-insensitive exact matches against the game's name or shortname. */
  names?: string[];
}

/** The part of a catalog entry matching reads. */
export interface BrandingEntryLike {
  id: string;
  match: BrandingMatch;
}

/** An entry with its regex precompiled. An invalid regex drops the pattern but
 *  keeps the entry, exactly as upstream does: its `names` still work. */
export interface CompiledBrandingEntry extends BrandingEntryLike {
  compiledRegex?: RegExp;
}

/** Compile every entry's regex, warning about and skipping the invalid ones.
 *  Extra fields on an entry ride along untouched, so callers that carry titles
 *  or picture lists do not have to zip the two arrays back together. */
export function compileBrandingEntries<T extends BrandingEntryLike>(
  entries: T[],
): (T & { compiledRegex?: RegExp })[] {
  return entries.map((entry) => {
    let compiledRegex: RegExp | undefined;
    if (entry.match.regex) {
      try {
        compiledRegex = new RegExp(entry.match.regex, "i");
      } catch {
        console.warn(`branding: entry "${entry.id}" has an invalid regex, skipped`);
      }
    }
    return { ...entry, compiledRegex };
  });
}

const eq = (a: string, b?: string | null) => !!b && a.toLowerCase() === b.toLowerCase();

/**
 * Does this entry match the game? Names are checked before the regex, exact
 * case-insensitively against both the name and the shortname; the regex is
 * tested case-insensitively against the name alone.
 *
 * The name is the game's display name where the hub holds one, since that is
 * what upstream's `game.name` is: "Splinter Faction", not `SF`. A shortname is
 * never fed to the regex, which is also upstream's rule and is why
 * `metal_factions` matches nothing on its own - a title override from the same
 * catalog supplies the name the regex was written for.
 */
export function brandingEntryMatches(
  entry: CompiledBrandingEntry,
  name?: string | null,
  shortname?: string | null,
): boolean {
  if (entry.match.names?.some((n) => eq(n, name) || eq(n, shortname))) return true;
  if (name && entry.compiledRegex?.test(name)) return true;
  return false;
}

/**
 * The first entry that matches this game, top to bottom. Catalog authors order
 * entries most specific first, and the first match winning is what makes that
 * ordering mean anything. Null when nothing matches, which is the ordinary case
 * for most of the catalog's games.
 */
export function resolveBrandingEntry<T extends BrandingEntryLike>(
  entries: T[],
  game: { shortname: string; displayName?: string | null },
): T | null {
  for (const entry of entries) {
    if (brandingEntryMatches(entry, game.displayName ?? null, game.shortname)) {
      return entry;
    }
  }
  return null;
}
