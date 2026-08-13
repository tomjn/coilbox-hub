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
  declaredFootprint,
  ONE_BUILD_SQUARE,
  parseBlueprintPayload,
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
  /** Whether the payload said what this def stands on. False is a building
   *  standing on one square because nothing said otherwise, which is worth
   *  drawing differently from one square somebody measured. */
  sized: boolean;
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
    const declared = declaredFootprint(blueprint, building.def);
    const footprint = declared ?? ONE_BUILD_SQUARE;
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
      sized: declared !== undefined,
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
      sized: rect.sized,
      x: rect.left - left + BUILDING_GAP,
      y: rect.top - top + BUILDING_GAP,
      // A footprint is at least one square, so this never reaches zero.
      width: rect.width - BUILDING_GAP * 2,
      height: rect.height - BUILDING_GAP * 2,
    })),
  };
}

/**
 * Clear ground round the layout, in build squares.
 *
 * What makes the drawing a plan on a sheet rather than a base cropped to its
 * own edge (tomjn/coilbox#1506). One square is enough to read as room round the
 * base and little enough that it does not shrink the base to make room for
 * nothing.
 */
export const SHEET_MARGIN = 1;

/**
 * The most grid cells drawn across the long side of a layout.
 *
 * Coilbox draws this on a library card about a hundred pixels across, and below
 * roughly six pixels a cell a grid stops reading as a grid and becomes grey
 * fill. Sixteen holds that there, and the number is the launcher's rather than
 * the site's so that one layout is ruled the same way in both.
 */
const MOST_CELLS = 16;

/** The sheet a layout is drawn on: the box to draw, and the rule over it. */
export interface BlueprintSheet {
  /** The `viewBox`, in the layout's own build squares, which is the layout's
   *  box with {@link SHEET_MARGIN} of clear ground round it. */
  left: number;
  top: number;
  width: number;
  height: number;
  /** How many build squares one grid cell covers. */
  pitch: number;
  /** Where the grid lines fall, down the sheet then across it. */
  verticals: number[];
  horizontals: number[];
}

/**
 * The sheet to draw a layout on.
 *
 * The grid is the build grid at its own pitch while that stays readable, and a
 * doubling of it once a base is too big for that: a thirty square base drawn
 * with thirty rules is a grey wash, so it gets fifteen cells of two squares
 * each. The lines fall on real build square boundaries either way, so what the
 * grid says is true at every size, and only how much it says changes.
 */
export function blueprintSheet(shape: BlueprintShape): BlueprintSheet {
  const pitch = gridPitch(Math.max(shape.width, shape.height));
  const left = -SHEET_MARGIN;
  const top = -SHEET_MARGIN;
  const width = shape.width + SHEET_MARGIN * 2;
  const height = shape.height + SHEET_MARGIN * 2;
  return {
    left,
    top,
    width,
    height,
    pitch,
    verticals: rules(left, left + width, pitch),
    horizontals: rules(top, top + height, pitch),
  };
}

/** Doubling until the long side fits in {@link MOST_CELLS}. Doubling rather
 *  than dividing the extent, so the pitch is always a whole number of build
 *  squares and a line is always a place a building could stand. */
function gridPitch(extent: number): number {
  let pitch = 1;
  while (extent / pitch > MOST_CELLS) pitch *= 2;
  return pitch;
}

/** Every multiple of the pitch inside the sheet. Measured from the layout's own
 *  origin rather than from the sheet's edge, so the rules line up with the
 *  buildings rather than with the margin. */
function rules(from: number, to: number, pitch: number): number[] {
  const out: number[] = [];
  for (let at = Math.ceil(from / pitch) * pitch; at <= to; at += pitch) {
    // The rule on the origin comes out of that arithmetic as `-0`, which is a
    // value of its own to anything reading these back.
    out.push(at === 0 ? 0 : at);
  }
  return out;
}
