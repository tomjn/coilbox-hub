import { expect, test } from "bun:test";
import {
  brandingEntryMatches,
  compileBrandingEntries,
  resolveBrandingEntry,
} from "./brandingMatch";
import catalog from "./vendor/catalog.json";

/**
 * The matching rule, pinned against the real catalog. The vendored entries are
 * the fixtures: they are what coilbox's players actually see, and a change to
 * them or to upstream's rule has to show up here rather than pass silently.
 */

const entries = compileBrandingEntries(catalog.entries);

const byId = (id: string) => {
  const entry = entries.find((e) => e.id === id);
  if (!entry) throw new Error(`catalog lost its "${id}" entry`);
  return entry;
};

test("an exact name from the match list wins before any regex", () => {
  const mcl = byId("mechcommander-legacy");
  expect(brandingEntryMatches(mcl, "MCL")).toBe(true);
  expect(brandingEntryMatches(mcl, "mcl", "mcl")).toBe(true);
});

test("a shortname alone satisfies an entry that lists it", () => {
  const ba = byId("balanced-annihilation");
  expect(brandingEntryMatches(ba, null, "BA")).toBe(true);
  expect(brandingEntryMatches(ba, "Balanced Annihilation v1.3")).toBe(true);
});

test("a regex-only entry needs a name, and never matches on shortname", () => {
  // Upstream tests its regex against game.name only, so `SF` matches nothing:
  // the hub learns the display name from the same catalog's title override.
  const sf = byId("splinter-faction");
  expect(brandingEntryMatches(sf, null, "SF")).toBe(false);
  expect(brandingEntryMatches(sf, "SplinterFaction")).toBe(true);
  expect(brandingEntryMatches(sf, "splinter faction")).toBe(true);
});

test("an underscored shortname does not satisfy a spaced regex", () => {
  // `metal_factions` against /^Metal *Factions/i fails on the underscore, which
  // is the rule working as upstream wrote it, not a bug to paper over here.
  const mf = byId("metal-factions");
  expect(brandingEntryMatches(mf, null, "metal_factions")).toBe(false);
  expect(brandingEntryMatches(mf, "Metal Factions")).toBe(true);
});

test("an invalid regex keeps the entry usable by name", () => {
  const [broken] = compileBrandingEntries([
    { id: "broken", match: { regex: "([unclosed" } },
  ]);
  expect(broken.compiledRegex).toBeUndefined();
  expect(brandingEntryMatches(broken, "anything")).toBe(false);
  const [named] = compileBrandingEntries([
    { id: "named", match: { regex: "([unclosed", names: ["still works"] } },
  ]);
  expect(brandingEntryMatches(named, "STILL WORKS")).toBe(true);
});

test("the first matching entry wins, in catalog order", () => {
  const resolved = resolveBrandingEntry(entries, { shortname: "SF", displayName: "SplinterFaction" });
  expect(resolved?.id).toBe("splinter-faction");
  expect(resolveBrandingEntry(entries, { shortname: "BA", displayName: null })?.id).toBe(
    "balanced-annihilation",
  );
});

test("a game nothing brands resolves to null", () => {
  expect(resolveBrandingEntry(entries, { shortname: "s44", displayName: "Spring 1944" })).toBeNull();
});
