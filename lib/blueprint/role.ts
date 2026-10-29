/**
 * What a building in a layout does, and the colour it is drawn in for it.
 *
 * A hand written subset of coilbox's `src/blueprint/role.ts`, which colours a
 * layout the way the in-game blueprint widget does: yellow for energy, red for
 * a building with a weapon, green for a builder. Not vendored, because that
 * file reaches its unit type through `@/content/bindings`, the Tauri binding
 * layer, and the hub's `@/` is a different root. The rule and the colours below
 * are upstream's word for word, and `scripts/sync-vendor.ts` checks that they
 * still are, so a change there goes red here rather than drawing a different
 * colour from the app.
 */

export type BuildingRole = "builder" | "weapon" | "energy" | "other";

/** The parts of a unit the rule reads: its build options and the stats blob
 *  `game_unit` stores as coilbox uploaded it. */
export interface RoleUnit {
  buildOptions?: string[];
  stats?: Record<string, unknown>;
}

/**
 * The widget's colours, as the red, green and blue fractions it writes them in.
 * A building that is none of the three keeps whatever colour the drawing
 * already gives a building, so `other` has no entry.
 */
export const ROLE_RGB: Record<Exclude<BuildingRole, "other">, number[]> = {
  builder: [0.4, 0.8, 0.4],
  weapon: [0.9, 0.33, 0.28],
  energy: [0.95, 0.8, 0.25],
};

/** The widget's colour for a building that is none of the three, as CSS. The
 *  widget writes it as `{ 0.45, 0.66, 0.74 }`, and coilbox's plan draws the
 *  same, so a base reads alike in all three (tomjn/coilbox#4781). */
export const BUILDING_CSS = "#73a8bd";

function declared(stats: Record<string, unknown>, key: string): number {
  const value = stats[key];
  return typeof value === "number" ? value : 0;
}

/** The widget's `M.category`. */
export function buildingRole(unit: RoleUnit): BuildingRole {
  const stats = unit.stats ?? {};
  if (stats.builder === true || (unit.buildOptions?.length ?? 0) > 0) {
    return "builder";
  }
  if (Array.isArray(stats.weapons) && stats.weapons.length > 0) {
    return "weapon";
  }
  if (
    declared(stats, "energyMake") > 0 ||
    declared(stats, "windGenerator") > 0 ||
    declared(stats, "tidalGenerator") > 0 ||
    declared(stats, "energyUpkeep") < 0
  ) {
    return "energy";
  }
  return "other";
}

/** A role's colour as CSS, for an SVG, or undefined for a building that keeps
 *  the drawing's own colour. */
export function roleCss(role: BuildingRole): string | undefined {
  if (role === "other") return undefined;
  const [r, g, b] = ROLE_RGB[role].map((part) => Math.round(part * 255));
  return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, "0")}`;
}

/**
 * What each building of a game does, keyed on the lower cased def, as it
 * crosses a `"use cache"` boundary. A plain object because a `Map` cannot
 * cross one. A def that is in no role, or that the catalog does not hold, is
 * absent, and is drawn in the plan's own colour.
 */
export type BuildingRoles = Readonly<Record<string, Exclude<BuildingRole, "other">>>;
