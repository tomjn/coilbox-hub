import { expect, test } from "bun:test";
import { nameFilter } from "./have";

test("a filter is an equality on the quoted name", () => {
  expect(nameFilter("Comet Catcher Remake 1.8")).toBe('map_name.eq."Comet Catcher Remake 1.8"');
});

/**
 * A canonical map name is free text and the engine reports whatever the archive
 * calls itself. Every one of these ends the filter early or changes what it means
 * if it arrives bare, and `postgrest-js` escapes none of them, which is why an
 * `in` list is not safe on this column however much simpler it would read.
 *
 * The failure it prevents is quiet: a filter that ends early matches nothing, the
 * name comes back absent from the lookup, and the caller is told the hub has never
 * seen a map it holds.
 */
test("a name full of PostgREST punctuation stays inside one operand", () => {
  expect(nameFilter('a,b.c(d):e"f\\g')).toBe('map_name.eq."a,b.c(d):e\\"f\\\\g"');
});

test("a hundred names stay under the request line most proxies allow", () => {
  const names = Array.from(
    { length: 100 },
    (_, index) => `A Long Enough Map Name To Be Realistic ${index}`,
  );

  expect(encodeURIComponent(names.map(nameFilter).join(",")).length).toBeLessThan(8000);
});
