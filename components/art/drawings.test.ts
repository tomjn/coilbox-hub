import { expect, test } from "bun:test";
import {
  BLUEPRINT_GAP,
  BLUEPRINT_PITCH,
  blueprint,
  palette,
} from "./drawings";

/**
 * The blueprint backdrop is a base laid out on a build grid, so its geometry
 * can be read straight back out of the markup and checked, the way the preview
 * arithmetic in `lib/gallery/blueprintPreview.test.ts` is. Nothing here judges
 * how it looks, only that what it draws is a layout rather than shapes
 * scattered near a grid.
 */

interface Mark {
  tag: string;
  attrs: Record<string, string>;
}

function marks(markup: string): Mark[] {
  return [...markup.matchAll(/<(\w+)\s+([^>]*?)\/?>/g)].map((match) => ({
    tag: match[1],
    attrs: Object.fromEntries(
      [...match[2].matchAll(/([\w-]+)="([^"]*)"/g)].map((attr) => [
        attr[1],
        attr[2],
      ]),
    ),
  }));
}

/** Every square the drawing puts on the ground, placed and unplaced alike. */
function squares(markup: string) {
  return marks(markup)
    .filter((mark) => mark.tag === "rect")
    .map((mark) => ({
      x: Number(mark.attrs.x),
      y: Number(mark.attrs.y),
      width: Number(mark.attrs.width),
      height: Number(mark.attrs.height),
      unplaced: "stroke-dasharray" in mark.attrs,
    }));
}

const MARKUP = blueprint.paint(palette, 1);

test("every square stands on the build grid", () => {
  const onGrid = (v: number) => expect(v % BLUEPRINT_PITCH).toBeCloseTo(0);

  for (const square of squares(MARKUP)) {
    // The gap comes off each square rather than out of the ground between
    // them, the same trick `blueprintPreview.ts` uses, so it has to be added
    // back before the grid shows through.
    onGrid(square.x - BLUEPRINT_GAP);
    onGrid(square.y - BLUEPRINT_GAP);
    onGrid(square.width + BLUEPRINT_GAP * 2);
    onGrid(square.height + BLUEPRINT_GAP * 2);
  }
});

test("the layout is a base rather than one building, at more than one size", () => {
  const drawn = squares(MARKUP);
  expect(drawn.length).toBeGreaterThan(6);
  expect(new Set(drawn.map((s) => `${s.width}x${s.height}`)).size).toBeGreaterThan(2);
});

test("no two buildings stand on the same ground", () => {
  const drawn = squares(MARKUP);

  for (const [i, a] of drawn.entries()) {
    for (const b of drawn.slice(i + 1)) {
      // Ground, not ink: the drawn squares are inset by the gap, so two
      // neighbours that share a grid line do not overlap on screen even when
      // they stand on the same square.
      const overlaps =
        a.x - BLUEPRINT_GAP < b.x + b.width + BLUEPRINT_GAP &&
        b.x - BLUEPRINT_GAP < a.x + a.width + BLUEPRINT_GAP &&
        a.y - BLUEPRINT_GAP < b.y + b.height + BLUEPRINT_GAP &&
        b.y - BLUEPRINT_GAP < a.y + a.height + BLUEPRINT_GAP;
      expect(overlaps).toBe(false);
    }
  }
});

test("exactly one square is left unplaced, for the order to end on", () => {
  expect(squares(MARKUP).filter((s) => s.unplaced).length).toBe(1);
});

test("the build order runs building to building, ending at the unplaced square", () => {
  const centre = (s: {
    x: number;
    y: number;
    width: number;
    height: number;
  }) => `${s.x + s.width / 2},${s.y + s.height / 2}`;
  const drawn = squares(MARKUP);
  const centres = new Set(drawn.map(centre));

  const thread = marks(MARKUP).find(
    (mark) => mark.tag === "path" && "stroke-dasharray" in mark.attrs,
  );
  const stops = thread!.attrs.d
    .split(/\s*[ML]\s*/)
    .filter(Boolean)
    .map((point) => point.trim().replace(/\s+/, ","));

  expect(stops.length).toBeGreaterThan(2);
  for (const stop of stops) expect(centres).toContain(stop);
  expect(stops.at(-1)).toBe(centre(drawn.find((s) => s.unplaced)!));

  // The order has to start somewhere, and the spark says where.
  const start = marks(MARKUP).find((mark) => mark.tag === "circle");
  expect(`${start!.attrs.cx},${start!.attrs.cy}`).toBe(stops[0]);
});

test("nothing in the blueprint drawing is left at the SVG default opacity", () => {
  // Every group here carries the opacity for what it holds, so a mark that
  // paints without one is the `archives` bug (coilbox#1382) again: a stray
  // full-strength line across a backdrop tuned down to a whisper.
  for (const mark of marks(MARKUP)) {
    if (mark.attrs.fill && mark.attrs.fill !== "none") {
      expect(mark.attrs).toHaveProperty("fill-opacity");
    }
    if (mark.attrs.stroke && mark.attrs.stroke !== "none") {
      expect(mark.attrs).toHaveProperty("stroke-opacity");
    }
  }
});
