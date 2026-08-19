import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import { MapMinimap, type MinimapFacts } from "@/components/MapMinimap";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { MapPoint } from "@/lib/maps/facts";
import { mapSquares } from "@/lib/maps/labels";

/**
 * What an item page says about the map it is played on, proved by rendering it
 * (#191). The two states are the whole component: a map the catalog holds, which
 * gets a link and the facts, and a map it does not, which has to keep rendering
 * the way it did before the catalog existed.
 */

const COMET = "Comet Catcher Remake 1.8";
const SLUG = "comet-catcher-remake-1-8";

/** A 12 x 20, which is the shape a square hides every mistake on. */
const SIZE = { width_elmos: 6144, height_elmos: 10240 };

function start(count: number): MapPoint[] {
  return Array.from({ length: count }, (_, index) => ({
    x: index * 512,
    z: index * 512,
    y: null,
    meta: null,
  }));
}

function facts(overrides: Partial<MinimapFacts> = {}): MinimapFacts {
  return {
    slug: SLUG,
    ...SIZE,
    points: { start: start(8), metal: [], geo: [] },
    ...overrides,
  };
}

const stored: ResolvedAsset = {
  from: "static",
  url: "https://example.test/maps/minimap/comet.webp",
  served: { keyedOn: "map", mapName: COMET, variant: MAP_MINIMAP_VARIANT },
  substituted: false,
  width: 512,
  height: 512,
};

const drawn: ResolvedAsset = {
  from: "placeholder",
  name: COMET,
  keyedOn: "map",
  footprint: mapSquares(SIZE.width_elmos, SIZE.height_elmos),
};

/** Nothing stored and nothing known, which is what an item page showed for
 *  every map between #180 and this. */
const unknown: ResolvedAsset = {
  from: "placeholder",
  name: COMET,
  keyedOn: "map",
  footprint: null,
};

function render(picture: ResolvedAsset, catalog: MinimapFacts | null, note: string | null = null) {
  return renderToStaticMarkup(
    <MapMinimap name={COMET} picture={picture} note={note} catalog={catalog} />,
  );
}

test("a map the catalog holds is linked to its own page", () => {
  expect(render(stored, facts())).toContain(`href="/map/${SLUG}"`);
});

/** A map nobody has submitted has no page to link to, and a link to one would
 *  be a link to a 404. */
test("a map the catalog does not hold is not linked", () => {
  expect(render(stored, null)).not.toContain('href="/map/');
});

test("the caption is the size and the player count, in the catalog's own words", () => {
  const html = render(stored, facts());

  expect(html).toContain("12 x 20, 8 players");
});

test("a map the catalog does not hold gets no caption of facts", () => {
  const html = render(stored, null);

  expect(html).not.toContain("12 x 20");
  expect(html).not.toContain("players");
});

/**
 * A map with no start positions stored is an incomplete extraction rather than a
 * map nobody can play, so the size is said and the count is left out.
 * `lib/maps/labels.ts` holds that reading.
 */
test("a map with no start positions is captioned with its size alone", () => {
  const html = render(stored, facts({ points: { start: [], metal: [], geo: [] } }));

  expect(html).toContain("12 x 20");
  expect(html).not.toContain("players");
});

/** The link is on the drawing too. A map with no picture is the case the link is
 *  worth most in: the page has nothing to show and the catalog has the facts. */
test("the drawing standing in for a picture is linked as well", () => {
  expect(render(drawn, facts())).toContain(`href="/map/${SLUG}"`);
});

/**
 * The state this component was built around, and the one #191 must not disturb:
 * the drawing, and the note about start positions if the item carries one.
 *
 * The whole markup rather than a few assertions about it, because "unchanged" is
 * the claim being made and anything less proves a part of it.
 */
test("a map the hub knows nothing about renders as it did before the catalog", () => {
  const note = "Start positions are fixed.";
  const before = renderToStaticMarkup(
    <figure className="flex flex-col gap-2">
      <AssetPlaceholder of={unknown} />
      <figcaption className="mt-auto flex flex-col gap-0.5 text-xs text-neutral-400">
        <span>{note}</span>
      </figcaption>
    </figure>,
  );

  expect(render(unknown, null, note)).toBe(before);
});

/** An item that says nothing about start positions has no caption at all, which
 *  is a figure of one drawing and was the ordinary case. */
test("a map the hub knows nothing about and no note has no caption", () => {
  expect(render(unknown, null)).not.toContain("figcaption");
});
