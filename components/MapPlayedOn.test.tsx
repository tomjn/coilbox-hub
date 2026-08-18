import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapPlayedOn } from "@/components/MapPlayedOn";
import type { ItemSummary } from "@/lib/gallery/query";

const COMET = "Comet Catcher Remake 1.8";

function item(overrides: Partial<ItemSummary> = {}): ItemSummary {
  return {
    id: "00000000-0000-0000-0000-0000000000ff",
    kind: "preset",
    mode: null,
    title: "A cautious opening",
    description: "",
    game_name: "Beyond All Reason",
    game_key: "bar",
    map_name: COMET,
    tags: [],
    author_name: "somebody",
    created_at: "2026-08-01T00:00:00Z",
    ...overrides,
  };
}

/**
 * Most maps have nothing published for them, so an empty section would be a
 * heading and a blank space on almost every page in the catalog.
 */
test("a map nothing has been published for renders no section at all", () => {
  expect(
    renderToStaticMarkup(<MapPlayedOn mapName={COMET} items={[]} origin="https://example.test" />),
  ).toBe("");
});

test("a map with items renders them as the cards the gallery draws", () => {
  const html = renderToStaticMarkup(
    <MapPlayedOn mapName={COMET} items={[item()]} origin="https://example.test" />,
  );

  expect(html).toContain("Played on this map");
  expect(html).toContain("A cautious opening");
  expect(html).toContain("/item/00000000-0000-0000-0000-0000000000ff");
});

/** A card's own links land back in a gallery already filtered to this map. */
test("the cards carry this map's filter with them", () => {
  const html = renderToStaticMarkup(
    <MapPlayedOn mapName={COMET} items={[item()]} origin="https://example.test" />,
  );

  expect(html).toContain("map=Comet+Catcher+Remake+1.8");
});
