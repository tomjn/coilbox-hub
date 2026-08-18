import { expect, test } from "bun:test";
import { mapSlug, slugAlternative } from "./slug";

test("a canonical map name becomes a name for a URL", () => {
  expect(mapSlug("Comet Catcher Remake 1.8")).toBe("comet-catcher-remake-1-8");
});

test("punctuation collapses to one hyphen and the ends are trimmed", () => {
  expect(mapSlug("  [BAR] Tangerine!! v1.1 ")).toBe("bar-tangerine-v1-1");
});

/**
 * Mappers are worldwide and their maps are named in their own scripts. Folding
 * to ASCII would empty every one of those slugs and send them all through the
 * fallback, where they would be a row of hex with nothing readable in it.
 */
test("a name outside ASCII keeps its letters", () => {
  expect(mapSlug("Зоя 1.0")).toBe("зоя-1-0");
  expect(mapSlug("Zoë's Map")).toBe("zoë-s-map");
});

/** The same accented character has more than one byte sequence, and two clients
 * can send different ones for one name. */
test("two spellings of one accented name give one slug", () => {
  expect(mapSlug("Zoë 1.0")).toBe(mapSlug("Zoë 1.0"));
});

/**
 * The column refuses a blank, so a name with nothing sluggable in it would be a
 * map that could never be stored. Nothing in the archive format stops a name
 * like that.
 */
test("a name with nothing sluggable in it still gets a name", () => {
  expect(mapSlug("!!! ???")).toBe("map");
});

test("a slug fits the column, however long the name is", () => {
  expect(mapSlug("Very Long Map ".repeat(40)).length).toBeLessThanOrEqual(256);
  expect(mapSlug("Very Long Map ".repeat(40)).endsWith("-")).toBe(false);
});

/**
 * Two different canonical names can render to one slug, and the unique index
 * would refuse the second map outright. The alternative is what stops a real
 * map losing its facts to a URL collision.
 */
test("two names that slug the same have different alternatives", async () => {
  expect(mapSlug("Comet Catcher 1.8")).toBe(mapSlug("Comet_Catcher 1.8"));

  expect(await slugAlternative("Comet Catcher 1.8")).not.toBe(
    await slugAlternative("Comet_Catcher 1.8"),
  );
});

/** Taken from the name rather than the facts, so the URL does not move when a
 * better extraction improves the map. */
test("an alternative is the slug plus a suffix from the name, and is stable", async () => {
  const alternative = await slugAlternative("Comet Catcher Remake 1.8");

  expect(alternative).toMatch(/^comet-catcher-remake-1-8-[0-9a-f]{8}$/);
  expect(await slugAlternative("Comet Catcher Remake 1.8")).toBe(alternative);
  expect(alternative.length).toBeLessThanOrEqual(256);
});

test("an alternative fits the column too, however long the name is", async () => {
  expect((await slugAlternative("Very Long Map ".repeat(40))).length).toBeLessThanOrEqual(256);
});
