import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapCard } from "@/components/MapCard";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { type MapSummary, parseFilters } from "@/lib/maps/query";

const MAP: MapSummary = {
  id: "1",
  map_name: "Comet Catcher Remake 1.8",
  slug: "comet-catcher-remake-1-8",
  display_name: null,
  width_elmos: 6144,
  height_elmos: 10240,
  tags: [],
  start_positions: 2,
  author_keys: [],
  author_names: [],
};

const PICTURE: ResolvedAsset = {
  from: "static",
  url: "https://example.test/comet.webp",
  served: { keyedOn: "map", mapName: MAP.map_name, variant: MAP_MINIMAP_VARIANT },
  substituted: false,
  width: 512,
  height: 512,
};

test("a card below the first rows loads its minimap lazily", () => {
  const html = renderToStaticMarkup(
    <MapCard map={MAP} picture={PICTURE} filters={parseFilters({})} />,
  );
  expect(html).toContain('loading="lazy"');
  expect(html).toContain('decoding="async"');
});

test("a card the reader sees first loads its minimap at once", () => {
  const html = renderToStaticMarkup(
    <MapCard map={MAP} picture={PICTURE} filters={parseFilters({})} eager />,
  );
  expect(html).not.toContain("loading=");
  expect(html).toContain('decoding="async"');
});
