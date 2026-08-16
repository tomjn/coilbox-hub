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
  type PayloadFootprint,
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
 * What the plan says to somebody who cannot see it (tomjn/coilbox#1511).
 *
 * Here rather than in the drawing because coilbox draws the same plan and says
 * the same sentence about it, in `src/hub/preview.ts`. Two drawings of one base
 * that describe themselves differently are worse than either on its own, so
 * each repo keeps the sentence beside the arithmetic it describes.
 *
 * The sides are rounded because a footprint gap leaves them fractional, and
 * "5.76 by 3.24 build squares" is a measurement nobody asked for.
 */
export function planLabel(shape: BlueprintShape): string {
  const buildings = shape.squares.length;
  return (
    `${buildings} building${buildings === 1 ? "" : "s"} over ` +
    `${Math.round(shape.width)} by ${Math.round(shape.height)} build squares`
  );
}

/** One kind of building in a layout. */
export interface BuildingKind {
  /** The def name in lower case, which is how the payload keys a footprint and
   *  how the hub keys a picture. A layout that spells one def two ways is one
   *  kind here. */
  def: string;
  count: number;
  /** What one of them stands on, or null where the payload never said. Left as
   *  the unit was defined rather than as any one of them was turned, because a
   *  facing is a fact about a placement and this counts units. */
  footprint: PayloadFootprint | null;
}

/**
 * What a layout is made of, most numerous kind first (issue #93).
 *
 * The plan says a base has twelve buildings and draws twelve squares. Without
 * this it never says what any of them are, so unless the publisher wrote it in
 * the title a reader has to guess from the shapes.
 *
 * The names are the game's internal ones, `coldfusionpowerplant` rather than
 * "Cold Fusion Power Plant". Turning one into the other takes unitsync and the
 * game installed, which is the same thing the hub does not have and the reason
 * it draws squares rather than unit models. The internal name is what the
 * payload carries, and a name a reader can search for beats a square with
 * nothing said about it.
 *
 * Ordered by count because what a base is mostly made of is the thing worth
 * reading first, and ties by name so one layout always lists the same way.
 */
export function blueprintRoster(payload: unknown): BuildingKind[] {
  const blueprint = parseBlueprintPayload(payload);
  if (!blueprint) return [];

  const counts = new Map<string, number>();
  for (const building of blueprint.buildings) {
    const def = building.def.toLowerCase();
    counts.set(def, (counts.get(def) ?? 0) + 1);
  }

  return [...counts]
    .map(([def, count]) => ({
      def,
      count,
      footprint: declaredFootprint(blueprint, def) ?? null,
    }))
    .sort((a, b) => b.count - a.count || a.def.localeCompare(b.def));
}

/** A stretch of the build order spent on one kind of building. */
export interface BuildingRun {
  /** Lower cased, the way {@link BuildingKind} and the picture lookup key a
   *  def. */
  def: string;
  count: number;
  /** Where this run starts and ends in the build order, counting the first
   *  building as one. A run of one has both ends on the same number. */
  from: number;
  to: number;
  /** What one of them stands on, or null where the payload never said. */
  footprint: PayloadFootprint | null;
}

/**
 * The build order as runs, or null for a layout that has no build order.
 *
 * The plan draws the order as a thread through the buildings, which says where
 * the base grows but never what is being built. This says it in words.
 *
 * Consecutive buildings of one kind collapse into a run, because a reader
 * following an order wants "four solar collectors, then a metal extractor",
 * not four rows saying the same thing. The same kind built again later is a
 * separate run rather than being added to the first: a base that lays four
 * solar, a mex, then four more solar built them in that order, and totalling
 * them would erase the only thing this list is for. What a layout is made of,
 * totalled, is {@link blueprintRoster}.
 *
 * Null when `ordered` is not set, which is most layouts. The order of
 * `buildings` is then how the layout was stored rather than a sequence anybody
 * chose, and numbering it would present a build order the payload never
 * claimed. The plan drops its thread on the same test.
 */
export function blueprintBuildOrder(payload: unknown): BuildingRun[] | null {
  const blueprint = parseBlueprintPayload(payload);
  if (!blueprint || blueprint.ordered !== true) return null;
  if (blueprint.buildings.length === 0) return null;

  const runs: BuildingRun[] = [];
  for (const [index, building] of blueprint.buildings.entries()) {
    const def = building.def.toLowerCase();
    const open = runs.at(-1);
    if (open?.def === def) {
      open.count += 1;
      open.to = index + 1;
      continue;
    }
    runs.push({
      def,
      count: 1,
      from: index + 1,
      to: index + 1,
      footprint: declaredFootprint(blueprint, def) ?? null,
    });
  }

  return runs;
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
 * The biggest a build square is ever drawn, in CSS pixels.
 *
 * Without this a two building layout fills the box it is given, and a base is
 * drawn at whatever zoom makes it fill the space rather than at a size that
 * says how big it is. Sixteen pixels is a generous build square, so only a
 * layout small enough to look silly blown up is held back to it.
 */
const MOST_PX_PER_SQUARE = 16;

/**
 * The smallest a build square can be drawn and still be worth ruling, in CSS
 * pixels.
 *
 * Below this the rules are closer together than the eye can separate and the
 * grid becomes grey fill, which says less than a blank sheet does. A base that
 * big gets no grid rather than a grid nobody can read.
 */
const LEAST_RULED_PX = 3;

/** The box a plan is drawn in, in CSS pixels. Both sides are positive. */
export interface PlanBox {
  width: number;
  height: number;
}

/** The sheet a layout is drawn on: the box to draw, and the rule over it. */
export interface BlueprintSheet {
  /** The `viewBox`, in the layout's own build squares. It covers the whole box
   *  the plan is drawn in, with the layout centred on it. */
  left: number;
  top: number;
  width: number;
  height: number;
  /** How many CSS pixels one build square is drawn at. What lets the drawing
   *  choose weights in pixels, the way its strokes already do. */
  scale: number;
  /** Where the grid lines fall, down the sheet then across it. Empty when a
   *  build square is too small to rule. */
  verticals: number[];
  horizontals: number[];
}

/**
 * The sheet to draw a layout on, given the box it is drawn in.
 *
 * Every build square is ruled, and the rules run the whole box rather than a
 * patch in the middle of it (tomjn/coilbox#1508). Both follow from what a plan
 * is for.
 *
 * Ruling every build square is what makes the grid true. A footprint stands on
 * whole build squares, so on a grid of single squares every building edge lands
 * on a rule. A coarser grid cannot do that: a five square solar collector on
 * rules every second square straddles them, and a grid the buildings ignore is
 * worse than no grid, because it invites exactly the reading it then
 * contradicts. What a big base costs is therefore weight rather than truth. The
 * rules close up, the drawing fades them, and past {@link LEAST_RULED_PX} it
 * stops drawing them.
 *
 * Running them the whole box is what makes it a sheet. Sized to the layout, the
 * grid was a patch of graph paper floating on a blank page. The layout keeps
 * its clear ground on all sides, {@link SHEET_MARGIN}, and then the sheet grows
 * out to the box on whichever side has room.
 */
export function blueprintSheet(
  shape: BlueprintShape,
  box: PlanBox,
): BlueprintSheet {
  const across = shape.width + SHEET_MARGIN * 2;
  const down = shape.height + SHEET_MARGIN * 2;
  // Fit the layout and its clear ground, without stretching one axis past the
  // other, and without blowing a small base up to fill the box.
  const scale = Math.min(
    box.width / across,
    box.height / down,
    MOST_PX_PER_SQUARE,
  );
  const width = box.width / scale;
  const height = box.height / scale;
  // The layout runs from zero to its own width, so centring it puts the same
  // amount of sheet on each side of it.
  const left = (shape.width - width) / 2;
  const top = (shape.height - height) / 2;
  const ruled = scale >= LEAST_RULED_PX;
  return {
    left,
    top,
    width,
    height,
    scale,
    verticals: ruled ? rules(left, left + width) : [],
    horizontals: ruled ? rules(top, top + height) : [],
  };
}

/** Every build square boundary inside the sheet. Counted off the layout's own
 *  origin rather than the sheet's edge, so the rules line up with the buildings
 *  rather than with the margin. */
function rules(from: number, to: number): number[] {
  const out: number[] = [];
  for (let at = Math.ceil(from); at <= to; at += 1) {
    // The rule on the origin comes out of that arithmetic as `-0`, which is a
    // value of its own to anything reading these back.
    out.push(at === 0 ? 0 : at);
  }
  return out;
}
