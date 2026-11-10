/**
 * The card half of the Next app's `lib/gallery/cardShapes.ts`: which drawing a
 * card gets from the parts of its container. The database read stays in PHP.
 */

import type { BuildingRoles } from "@/lib/blueprint/role";
import {
  type BlueprintShape,
  blueprintShape,
} from "@/lib/gallery/blueprintPreview";
import {
  conquestGalaxy,
  type GalaxyShape,
} from "@/lib/gallery/conquestGalaxy";
import { type RunShape, warpathRun } from "@/lib/gallery/warpathRun";

export type CardShape =
  | { type: "galaxy"; galaxy: GalaxyShape }
  | { type: "run"; run: RunShape }
  | { type: "blueprint"; layout: BlueprintShape; roles: BuildingRoles };

export interface CardShapeParts {
  settings: unknown;
  name: unknown;
  ordered: unknown;
  buildings: unknown;
  terrain: unknown;
  footprints: unknown;
}

/** The galaxy without the words on it, which is all a card ever draws. */
function unnamed(galaxy: GalaxyShape): GalaxyShape {
  return {
    ...galaxy,
    systems: galaxy.systems.map(({ x, y, faction, capital }) => ({
      x,
      y,
      faction,
      capital,
    })),
    factions: galaxy.factions.map(({ color }) => ({ color })),
  };
}

export function cardShape(
  item: { kind: string; mode: string | null },
  parts: CardShapeParts,
  roles: BuildingRoles = {},
): CardShape | null {
  if (item.kind === "challenge") {
    const payload = { mode: item.mode, settings: parts.settings };
    if (item.mode === "conquest") {
      const galaxy = conquestGalaxy(payload);
      return galaxy ? { type: "galaxy", galaxy: unnamed(galaxy) } : null;
    }
    if (item.mode === "warpath") {
      const run = warpathRun(payload);
      return run ? { type: "run", run } : null;
    }
    return null;
  }

  if (item.kind === "blueprint") {
    const layout = blueprintShape({
      name: parts.name,
      ordered: parts.ordered,
      buildings: parts.buildings,
      terrain: parts.terrain,
      footprints: parts.footprints,
    });
    return layout ? { type: "blueprint", layout, roles } : null;
  }

  return null;
}
