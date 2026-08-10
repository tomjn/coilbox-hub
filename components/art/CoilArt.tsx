import type { Drawing } from "./drawings";
import { palette, WIDTH } from "./drawings";

/** The full authored canvas height every drawing paints against, regardless
 * of how much of it a given drawing's `viewHeight` crops to. Pool rects still
 * fill this so a pool centred below the crop still glows into frame. */
const HEIGHT_CONSTANT = 200;

/**
 * Renders one `Drawing` from `drawings.ts` as an SVG backdrop. This is the
 * renderer `components/HubArt.tsx` used to hold a private copy of, before
 * this site had more than one drawing to render. Every drawing shares this
 * one instance rather than copying it again.
 *
 * Built fresh per render from fixed constants, the drawing's own data and
 * this instance's `strength`, never from anything a visitor supplies.
 */
function buildInner(drawing: Drawing, strength: number): string {
  return (
    "<defs>" +
    drawing.pools
      .map(
        ({ cx, cy, r, opacity }, i) =>
          `<radialGradient id="${drawing.id}-pool${i}" cx="${cx}" cy="${cy}" r="${r}" gradientUnits="userSpaceOnUse">` +
          `<stop offset="0" stop-color="${palette.glow}" stop-opacity="${opacity}"/>` +
          `<stop offset="1" stop-color="${palette.glow}" stop-opacity="0"/>` +
          "</radialGradient>",
      )
      .join("") +
    "</defs>" +
    drawing.pools
      .map(
        (_, i) =>
          `<rect width="${WIDTH}" height="${HEIGHT_CONSTANT}" fill="url(#${drawing.id}-pool${i})"/>`,
      )
      .join("") +
    drawing.paint(palette, strength)
  );
}

export function CoilArt({
  drawing,
  className,
  strength = 1,
}: {
  drawing: Drawing;
  className?: string;
  /** Scales the shape opacities in `drawing.paint` down from their card
   * tuning. Left at 1 for a caller that wants that tuning as is. Passed lower
   * by a caller that puts the drawing behind something the card never had to
   * contend with, such as running text. The pool glow is not scaled: each
   * pool's own `opacity` in `drawings.ts` is tuned separately. */
  strength?: number;
}) {
  return (
    <svg
      viewBox={`0 0 ${WIDTH} ${drawing.viewHeight}`}
      // The drawing has no background of its own, so as a full-bleed backdrop
      // it must cover its container edge to edge rather than letterbox:
      // "slice" scales up until both dimensions are covered, "xMidYMax" keeps
      // the crop centred horizontally and keeps the drawing's own foot
      // pinned to the bottom edge whatever gets trimmed off the sides.
      preserveAspectRatio="xMidYMax slice"
      role="img"
      aria-label={drawing.ariaLabel}
      className={className}
      dangerouslySetInnerHTML={{ __html: buildInner(drawing, strength) }}
    />
  );
}
