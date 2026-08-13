/**
 * A shared layout as squares on a grid, for the blueprint preview in
 * `components/ItemPreview.tsx`. Pure arithmetic, split out so it can be tested
 * without rendering anything.
 *
 * The hub has no unit models and no unit pictures, so a building is a square.
 * What stops that being a diagram of nothing is the footprint: it travels in
 * the payload because it comes from the unit definition, which means unitsync,
 * which means the game installed, and the hub has none of that. So a factory
 * really is bigger than a solar collector here.
 *
 * Two things the payload measures in different units meet here. Offsets are in
 * elmos and footprints are in build squares, so everything is converted to
 * build squares, which is also what makes a sensible `viewBox`.
 *
 * Positions are drawn where the layout puts them. The engine snaps a building
 * to the build grid when it is placed, and coilbox does that snapping in the
 * editor, so a layout made there arrives already on the grid. Repeating the
 * snap here would mean restating engine arithmetic the hub cannot vendor, to
 * move a hand written layout by at most half a square.
 */

import {
  BUILD_SQUARE_ELMOS,
  parseBlueprintPayload,
  payloadFootprint,
} from "@/lib/blueprint/payload";

/**
 * Ground left clear on each side of a building, in build squares.
 *
 * Buildings in a real base stand shoulder to shoulder, and squares drawn true
 * to size would touch and read as one shape. Taking the gap off each building
 * rather than adding it between them keeps the layout to scale: the distance
 * between two buildings stays what the author placed.
 */
export const BUILDING_GAP = 0.12;

/** One building, as a rectangle inside the box below. Build squares
 *  throughout, measured from the top left of the box. */
export interface BlueprintSquare {
  def: string;
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface BlueprintShape {
  /** The box every square fits inside, in build squares. */
  width: number;
  height: number;
  /** In the payload's own order, which is the build order when `ordered`. */
  squares: BlueprintSquare[];
  ordered: boolean;
}

/**
 * The squares to draw for a blueprint payload, or null when there is nothing to
 * draw. Reads the payload with coilbox's own parser, so the preview and the app
 * agree on what a layout is without the hub restating it.
 */
export function blueprintShape(payload: unknown): BlueprintShape | null {
  const blueprint = parseBlueprintPayload(payload);
  if (!blueprint || blueprint.buildings.length === 0) return null;

  const rects = blueprint.buildings.map((building) => {
    const footprint = payloadFootprint(blueprint, building.def);
    // Facings 1 and 3 are east and west, which is the building on its side, so
    // a 2 by 4 stands on 4 by 2 of the ground. The engine's
    // `BuildInfo::GetXSize`, and coilbox's `facedFootprint`.
    const turned = building.facing % 2 === 1;
    const width = turned ? footprint.z : footprint.x;
    const height = turned ? footprint.x : footprint.z;
    // An offset is the middle of the building, so the ground it stands on
    // reaches half a footprint either side of it.
    return {
      def: building.def,
      left: building.offset.x / BUILD_SQUARE_ELMOS - width / 2,
      top: building.offset.z / BUILD_SQUARE_ELMOS - height / 2,
      width,
      height,
    };
  });

  // Measured from the layout rather than from zero. Offsets run out from the
  // origin in both directions, so a box starting at zero would leave half the
  // base outside the picture.
  const left = Math.min(...rects.map((rect) => rect.left));
  const top = Math.min(...rects.map((rect) => rect.top));
  const right = Math.max(...rects.map((rect) => rect.left + rect.width));
  const bottom = Math.max(...rects.map((rect) => rect.top + rect.height));

  return {
    width: right - left,
    height: bottom - top,
    ordered: blueprint.ordered === true,
    squares: rects.map((rect) => ({
      def: rect.def,
      x: rect.left - left + BUILDING_GAP,
      y: rect.top - top + BUILDING_GAP,
      // A footprint is at least one square, so this never reaches zero.
      width: rect.width - BUILDING_GAP * 2,
      height: rect.height - BUILDING_GAP * 2,
    })),
  };
}
