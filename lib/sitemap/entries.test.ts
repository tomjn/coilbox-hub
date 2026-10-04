import { expect, test } from "bun:test";
import { SITEMAP_LIMIT, sitemapEntries } from "./entries";

const ORIGIN = "https://hub.example";

const EMPTY = { items: [], maps: [], games: [] };

test("the fixed pages are always listed", () => {
  expect(sitemapEntries(ORIGIN, EMPTY).map((entry) => entry.url)).toEqual([
    "https://hub.example/",
    "https://hub.example/gallery",
    "https://hub.example/maps",
    "https://hub.example/games",
    "https://hub.example/developers",
  ]);
});

test("each item, map, game and unit gets a page", () => {
  const urls = sitemapEntries(ORIGIN, {
    items: [{ id: "11111111-1111-4111-8111-111111111111", updated_at: "2026-10-03T09:00:00.5+00:00" }],
    maps: [{ slug: "seed-map" }],
    games: [{ shortname: "tabtest", units: ["armcom", "armmex"], releases: 2 }],
  }).map((entry) => entry.url);

  expect(urls).toContain("https://hub.example/item/11111111-1111-4111-8111-111111111111");
  expect(urls).toContain("https://hub.example/map/seed-map");
  expect(urls).toContain("https://hub.example/games/tabtest");
  expect(urls).toContain("https://hub.example/games/tabtest/units");
  expect(urls).toContain("https://hub.example/games/tabtest/changes");
  expect(urls).toContain("https://hub.example/games/tabtest/units/armcom");
  expect(urls).toContain("https://hub.example/games/tabtest/units/armmex");
});

test("the changes page is listed only for a game with two releases", () => {
  const urls = (releases: number) =>
    sitemapEntries(ORIGIN, { ...EMPTY, games: [{ shortname: "g", units: [], releases }] }).map((entry) => entry.url);
  expect(urls(1)).not.toContain("https://hub.example/games/g/changes");
  expect(urls(2)).toContain("https://hub.example/games/g/changes");
  expect(urls(2).filter((url) => url.includes("?"))).toEqual([]);
});

test("an item says when it last changed, in a form a crawler reads", () => {
  const entries = sitemapEntries(ORIGIN, {
    ...EMPTY,
    items: [{ id: "a", updated_at: "2026-10-03T09:00:00.5+00:00" }],
  });
  expect(entries.find((entry) => entry.url.endsWith("/item/a"))?.lastModified).toBe("2026-10-03T09:00:00.500Z");
});

test("a name with characters that need escaping in a path is escaped", () => {
  const urls = sitemapEntries(ORIGIN, {
    ...EMPTY,
    games: [{ shortname: "a b", units: ["x/y", "q?z"], releases: 0 }],
  }).map((entry) => entry.url);
  expect(urls).toContain("https://hub.example/games/a%20b/units/x%2Fy");
  expect(urls).toContain("https://hub.example/games/a%20b/units/q%3Fz");
});

test("a sitemap at the protocol limit is accepted", () => {
  const fixed = sitemapEntries(ORIGIN, EMPTY).length;
  const items = Array.from({ length: SITEMAP_LIMIT - fixed }, (_, i) => ({ id: String(i), updated_at: "2026-10-03T00:00:00Z" }));
  expect(sitemapEntries(ORIGIN, { ...EMPTY, items })).toHaveLength(SITEMAP_LIMIT);
});

test("a sitemap past the protocol limit fails rather than being cut short", () => {
  const fixed = sitemapEntries(ORIGIN, EMPTY).length;
  const items = Array.from({ length: SITEMAP_LIMIT - fixed + 1 }, (_, i) => ({ id: String(i), updated_at: "2026-10-03T00:00:00Z" }));
  expect(() => sitemapEntries(ORIGIN, { ...EMPTY, items })).toThrow(/50000|50,000/);
});

test("the protocol limit is 50,000 URLs", () => {
  expect(SITEMAP_LIMIT).toBe(50_000);
});
