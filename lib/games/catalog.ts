/**
 * The rows the game catalog tables hand back (#223), and the two small things
 * every reader of them needs.
 *
 * The migration `20260821100000_game_catalog.sql` is the authority on the
 * columns; these interfaces follow it so a page or a route that reads a row has
 * one spelling of its shape rather than five. The two helpers exist because
 * both halves of their jobs are decisions, not conveniences: what counts as a
 * usable link, and what counts as retired.
 */

export interface GameLink {
  label: string;
  url: string;
}

export interface GameRow {
  id: string;
  shortname: string;
  display_name: string | null;
  description: string | null;
  links: GameLink[];
  start_units: string[] | null;
  submitted_by: string | null;
  created_at: string;
  updated_at: string;
}

export interface GameVersionRow {
  id: number;
  game_id: string;
  version: string;
  first_seen_at: string;
  last_seen_at: string;
}

export interface GameFactionRow {
  id: number;
  game_id: string;
  key: string;
  name: string;
  logo_path: string | null;
  logo_hash: string | null;
}

export interface GameUnitRow {
  id: number;
  game_id: string;
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
  facts_digest: string;
  source_version: string | null;
  removed_at: string | null;
  last_seen_at: string;
  created_at: string;
  updated_at: string;
}

export interface GameUnitRevisionRow {
  id: number;
  unit_id: number;
  version: string;
  full_name: string | null;
  faction_key: string | null;
  build_options: string[];
  stats: Record<string, unknown>;
  facts_digest: string;
  recorded_at: string;
}

/**
 * A unit a complete submission stopped listing.
 *
 * Retired rather than deleted, because an old replay still names it, which is
 * why this is a question with an answer instead of an absent row.
 */
export function gameUnitIsRetired(unit: Pick<GameUnitRow, "removed_at">): boolean {
  return unit.removed_at !== null;
}

/**
 * The labelled links a game row carries, as links.
 *
 * jsonb arrives as whatever was stored, and the table only insists it is an
 * array. Anything inside it that is not an object carrying a non-empty label
 * and url is dropped rather than rendered, so one malformed entry costs itself
 * and not the page: a link that renders as `<a href="[object Object]">` is
 * worse than a missing one.
 */
export function parseGameLinks(value: unknown): GameLink[] {
  if (!Array.isArray(value)) return [];
  const links: GameLink[] = [];
  for (const entry of value) {
    if (typeof entry !== "object" || entry === null) continue;
    const { label, url } = entry as Record<string, unknown>;
    if (typeof label !== "string" || typeof url !== "string") continue;
    if (label.trim() === "" || url.trim() === "") continue;
    links.push({ label, url });
  }
  return links;
}
