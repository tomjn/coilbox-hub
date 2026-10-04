import { expect, test } from "bun:test";
import { FEED_ENTRIES, type FeedRow, galleryFeed, parseFeedKind } from "./gallery";

const ORIGIN = "https://hub.example";

const ROW: FeedRow = {
  id: "11111111-1111-4111-8111-111111111111",
  kind: "preset",
  mode: null,
  title: "Fast start",
  description: "Opens with two builders.",
  author_name: "Ada",
  created_at: "2026-10-01T10:00:00+00:00",
  updated_at: "2026-10-03T09:00:00.5+00:00",
};

test("no kind means the whole gallery", () => {
  expect(parseFeedKind(null)).toEqual({ ok: true, kind: null });
  expect(parseFeedKind("")).toEqual({ ok: true, kind: null });
});

test("a known kind is accepted", () => {
  expect(parseFeedKind("preset")).toEqual({ ok: true, kind: "preset" });
  expect(parseFeedKind("mod-project")).toEqual({ ok: true, kind: "mod-project" });
});

test("an unknown kind is refused, with the kinds that are known", () => {
  const result = parseFeedKind("presets");
  expect(result.ok).toBe(false);
  if (!result.ok) expect(result.error).toContain("preset, challenge, setup-pack, scenario, blueprint, mod-project");
});

test("kinds that only exist on Object.prototype are refused", () => {
  expect(parseFeedKind("constructor").ok).toBe(false);
  expect(parseFeedKind("__proto__").ok).toBe(false);
});

test("an entry id is the item's uuid, so it survives a change of domain", () => {
  const xml = galleryFeed([ROW], null, ORIGIN);
  expect(xml).toContain("<id>urn:uuid:11111111-1111-4111-8111-111111111111</id>");
});

test("an entry links to the item page on the configured origin", () => {
  const xml = galleryFeed([ROW], null, ORIGIN);
  expect(xml).toContain('href="https://hub.example/item/11111111-1111-4111-8111-111111111111"');
});

test("the feed time is the newest entry's update, not the clock", () => {
  const older = { ...ROW, id: "22222222-2222-4222-8222-222222222222", updated_at: "2026-09-01T00:00:00Z" };
  const xml = galleryFeed([older, ROW], null, ORIGIN);
  const head = xml.slice(0, xml.indexOf("<entry>"));
  expect(head).toContain("<updated>2026-10-03T09:00:00.500Z</updated>");
});

test("an empty feed has a fixed time and no entries", () => {
  const xml = galleryFeed([], null, ORIGIN);
  expect(xml).not.toContain("<entry>");
  expect(xml).toContain("<updated>1970-01-01T00:00:00.000Z</updated>");
});

test("the feed names itself, and a narrowed feed says which kind", () => {
  const all = galleryFeed([ROW], null, ORIGIN);
  expect(all).toContain("<id>https://hub.example/feed.xml</id>");
  expect(all).toContain('<title type="text">Coilbox Hub</title>');
  expect(all).toContain('rel="alternate" type="text/html" href="https://hub.example/gallery"');

  const presets = galleryFeed([ROW], "preset", ORIGIN);
  expect(presets).toContain("<id>https://hub.example/feed.xml?kind=preset</id>");
  expect(presets).toContain('<title type="text">Coilbox Hub: Presets</title>');
  expect(presets).toContain('href="https://hub.example/gallery?kind=preset"');
});

test("the category term is the kind and the label is what a player calls it", () => {
  const warpath = { ...ROW, kind: "challenge" as const, mode: "warpath" };
  const xml = galleryFeed([warpath], null, ORIGIN);
  expect(xml).toContain('<category term="challenge" label="Warpath"/>');
});

test("a blank author name leaves the entry to the feed's author", () => {
  const xml = galleryFeed([{ ...ROW, author_name: "" }], null, ORIGIN);
  const entry = xml.slice(xml.indexOf("<entry>"));
  expect(entry).not.toContain("<author>");
});

test("the feed holds the newest entries and no more", () => {
  const rows = Array.from({ length: FEED_ENTRIES + 5 }, (_, i) => ({
    ...ROW,
    id: `00000000-0000-4000-8000-${String(i).padStart(12, "0")}`,
  }));
  const xml = galleryFeed(rows, null, ORIGIN);
  expect(xml.match(/<entry>/g)).toHaveLength(FEED_ENTRIES);
});
