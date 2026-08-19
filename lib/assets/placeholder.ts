/**
 * The last rung of the serving ladder (issue #108): what to draw when the hub
 * holds no picture at all.
 *
 * This rung cannot fail, which is the whole reason it exists. Every rung above
 * it depends on a row, a tier and an object, and any of those can be absent. The
 * hub has no asset rows at all today, so until the seed lands this is the rung
 * every caller actually reaches.
 *
 * ## Why it is a drawing and not a URL
 *
 * The other rungs answer with a URL. This one answers with a description, and
 * `components/AssetPlaceholder.tsx` turns it into markup in the page that was
 * already being rendered. Nothing is fetched, no image is transformed, and no
 * function is invoked to produce it.
 *
 * The alternatives were both worse on a Hobby plan. A route that generates an
 * SVG or a PNG per request spends a function invocation on every missing picture
 * on the page, which is the one case guaranteed to be common. Generating them at
 * build time cannot work at all: the set of units and maps is whatever the
 * archives hold, not a list the build knows.
 *
 * Anything routed through `next/image` is out for the reason
 * `components/MapMinimap.tsx` already gives: around 5,000 transformations a
 * month, metered on unique source images, and a placeholder is by definition
 * unique per unit.
 *
 * ## What is in it
 *
 * The name, and the footprint. `lib/gallery/blueprintPreview.ts` already argues
 * why a footprint is worth drawing when there is no model and no picture: a
 * factory really is bigger than a solar collector, and the shape of the ground a
 * thing stands on is most of what a reader can be told without a photograph.
 */

import type { AssetIdentity } from "./asset";

/**
 * How much ground the thing stands on.
 *
 * The units are the caller's and depend on which key was asked for, because the
 * two are named differently everywhere else in the hub and unifying them here
 * would only move the confusion.
 *
 * For a unit it is build squares, which is what `declaredFootprint` in
 * `lib/blueprint/payload.ts` returns. For a map it is 512 elmo squares, which is
 * what `mapSquares` in `lib/maps/labels.ts` turns a catalog row into, so a 6144
 * elmo map is 12. Not elmos, and neither `public.map.width_elmos` nor
 * `public.asset.map_width`, both of which are: "12 by 12" is how a person
 * recognises a map and "6144 by 6144" is not.
 *
 * This used to name `mapWidth` on `BarMap`, which went with the map list #180
 * removed and the type that carried it.
 */
export interface Footprint {
  width: number;
  height: number;
}

/**
 * Everything needed to draw a picture that does not exist.
 *
 * A description rather than a component's props, so the ladder can be resolved
 * and tested with nothing rendered, in the same split
 * `lib/gallery/blueprintPreview.ts` makes.
 */
export interface MissingPicture {
  /** What the picture would have been of: the unit name or the map name. */
  name: string;
  /** Which key it was asked under, which is what says how to read the
   * footprint. */
  keyedOn: AssetIdentity["keyedOn"];
  /** Null when the caller had no dimensions, which draws a square rather than
   * refusing to draw. */
  footprint: Footprint | null;
}

/** The name off either shape of identity. */
export function missingPicture(
  identity: AssetIdentity,
  footprint: Footprint | null,
): MissingPicture {
  return {
    name: identity.keyedOn === "unit" ? identity.unitName : identity.mapName,
    keyedOn: identity.keyedOn,
    footprint,
  };
}

/** The longer side of the box a placeholder is drawn in, in user units. The
 *  shorter side is however much less the footprint says. */
const LONGEST = 100;

/**
 * The most out of square a placeholder is drawn, as a ratio of its sides.
 *
 * Not a correction to the footprint: nothing real reaches eight to one, so this
 * never fires on a genuine unit or map. It is there because the footprint comes
 * from a payload or a map list rather than from the hub, and a thousand to one
 * value would otherwise draw a line one pixel tall that reads as a rendering
 * fault.
 */
const MOST_ELONGATED = 8;

/** A dimension the drawing can use, or null for anything that would put a
 *  zero, a negative or a NaN into a `viewBox`. */
function usable(value: number): number | null {
  return Number.isFinite(value) && value > 0 ? value : null;
}

/**
 * The box to draw the footprint in, normalised so the longer side is
 * {@link LONGEST}.
 *
 * Normalised rather than drawn in the footprint's own numbers, so a 4 by 4
 * building and a 24 by 24 map produce the same `viewBox` and the strokes and
 * corners in the drawing are one set of numbers rather than a scale factor.
 *
 * A square when there is no footprint, or when the one supplied is not a pair of
 * numbers a box can be made from.
 */
export function placeholderBox(footprint: Footprint | null): Footprint {
  const square = { width: LONGEST, height: LONGEST };
  if (!footprint) return square;

  const width = usable(footprint.width);
  const height = usable(footprint.height);
  if (width === null || height === null) return square;

  const ratio = Math.min(Math.max(width / height, 1 / MOST_ELONGATED), MOST_ELONGATED);
  return ratio >= 1
    ? { width: LONGEST, height: LONGEST / ratio }
    : { width: LONGEST * ratio, height: LONGEST };
}

/** Footprints are whole numbers in both vocabularies, and a stray fraction from
 *  a payload is a measurement nobody asked for. */
function round(value: number): number {
  return Math.round(value * 10) / 10;
}

/**
 * The footprint as a caption, or null when there is none to say.
 *
 * A unit names its units because build squares are the hub's own vocabulary and
 * mean nothing unsaid. A map does not, because "12 by 12" is exactly how BAR,
 * the lobby and every player names a map size, and appending a noun to it would
 * invent one.
 */
export function placeholderMeasure(picture: MissingPicture): string | null {
  const footprint = picture.footprint;
  if (!footprint) return null;

  const width = usable(footprint.width);
  const height = usable(footprint.height);
  if (width === null || height === null) return null;

  const size = `${round(width)} by ${round(height)}`;
  return picture.keyedOn === "unit" ? `${size} build squares` : size;
}

/**
 * What the drawing says to somebody who cannot see it, in the same spirit as
 * `planLabel` in `lib/gallery/blueprintPreview.ts`.
 *
 * It says there is no picture rather than describing a box, because a reader
 * using a screen reader should learn the same thing a sighted reader learns from
 * a dashed outline: the hub has nothing of this yet.
 */
export function placeholderLabel(picture: MissingPicture): string {
  const measure = placeholderMeasure(picture);
  if (!measure) return `No picture of ${picture.name} yet`;

  return picture.keyedOn === "unit"
    ? `No picture of ${picture.name} yet, which stands on ${measure}`
    : `No picture of ${picture.name} yet, a ${measure} map`;
}
