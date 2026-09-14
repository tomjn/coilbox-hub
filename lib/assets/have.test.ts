import { expect, test } from "bun:test";
import type { AssetIdentity } from "./asset";
import { heldForHave, identityFilter, identityKey, queryChunks } from "./have";

const UNIT: AssetIdentity = {
  keyedOn: "unit",
  game: "bar",
  unitName: "armsolar",
  variant: "buildpic",
};

const MAP: AssetIdentity = {
  keyedOn: "map",
  mapName: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

test("the two shapes never produce the same lookup key", () => {
  expect(identityKey(UNIT)).not.toBe(
    identityKey({ keyedOn: "map", mapName: "armsolar", variant: "buildpic" }),
  );
});

test("a key cannot be forged by moving the boundary between two parts", () => {
  const left = identityKey({ keyedOn: "unit", game: "bar", unitName: "arm solar", variant: "buildpic" });
  const right = identityKey({ keyedOn: "unit", game: "bar arm", unitName: "solar", variant: "buildpic" });

  expect(left).not.toBe(right);
});

test("a unit filter names the game, since unit names repeat across games", () => {
  expect(identityFilter(UNIT)).toBe(
    'and(game.eq."bar",unit_name.eq."armsolar",variant.eq."buildpic")',
  );
});

test("a map filter names no game, since a map is not scoped to one", () => {
  expect(identityFilter(MAP)).toBe(
    'and(map_name.eq."Comet Catcher Remake 1.8",variant.eq."minimap")',
  );
});

/**
 * A map name is free text and the engine reports whatever the archive is
 * called. Every one of these ends the filter early or changes what it means if
 * it arrives bare, and `postgrest-js` escapes none of them.
 */
test("a map name full of PostgREST punctuation stays inside one operand", () => {
  expect(
    identityFilter({ keyedOn: "map", mapName: 'a,b.c(d):e"f\\g', variant: "minimap" }),
  ).toBe('and(map_name.eq."a,b.c(d):e\\"f\\\\g",variant.eq."minimap")');
});

test("a batch is split into requests small enough for a query string", () => {
  const identities = Array.from({ length: 500 }, (_, index) => index);

  const chunks = queryChunks(identities);

  expect(chunks).toHaveLength(10);
  expect(chunks[0]).toHaveLength(50);
  expect(chunks.flat()).toEqual(identities);
});

test("a batch that does not divide evenly keeps its remainder", () => {
  expect(queryChunks([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]]);
});

test("one query stays under the request line most proxies allow", () => {
  const identities: AssetIdentity[] = Array.from({ length: 50 }, (_, index) => ({
    keyedOn: "map",
    mapName: `A Long Enough Map Name To Be Realistic ${index}`,
    variant: "minimap",
  }));

  const query = encodeURIComponent(identities.map(identityFilter).join(","));

  expect(query.length).toBeLessThan(8000);
});

/** #336. Coilbox uploads whatever the hub does not answer `have` for. */
test("a row whose bytes the store lost is not held, so Coilbox offers it again", () => {
  const lost = "2026-09-14T12:00:00Z";

  expect(heldForHave({ moderation: "approved", bytes_missing_at: null })).toBe(true);
  expect(heldForHave({ moderation: "approved", bytes_missing_at: lost })).toBe(false);
  expect(heldForHave({ moderation: "pending", bytes_missing_at: lost })).toBe(false);
});

test("a rejected row is held whatever happened to its bytes, since an upload cannot undo it", () => {
  expect(heldForHave({ moderation: "rejected", bytes_missing_at: "2026-09-14T12:00:00Z" })).toBe(true);
});
