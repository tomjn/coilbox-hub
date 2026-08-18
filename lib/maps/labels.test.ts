import { expect, test } from "bun:test";
import { mapSizeLabel, mapSquares, playerCountLabel } from "./labels";

test("a map is named in squares rather than in elmos", () => {
  expect(mapSizeLabel(6144, 10240)).toBe("12 x 20");
});

/** The order is the row's own, so a tall map does not read as a wide one. */
test("width comes first and height second", () => {
  expect(mapSizeLabel(10240, 6144)).toBe("20 x 12");
});

/** Nothing in the table makes an edge a whole number of squares, so a row that
 *  is not says so rather than being rounded into one. */
test("an edge that is not a whole square keeps the fraction", () => {
  expect(mapSizeLabel(6400, 6144)).toBe("12.5 x 12");
});

test("the footprint a placeholder is drawn from is the same pair of squares", () => {
  expect(mapSquares(6144, 10240)).toEqual({ width: 12, height: 20 });
});

test("a player count reads as people rather than as points", () => {
  expect(playerCountLabel(8)).toBe("8 players");
  expect(playerCountLabel(1)).toBe("1 player");
});

/** An incomplete extraction is not a map nobody can play, so the page says
 *  nothing rather than saying nobody. */
test("a map with no start positions has no player count to give", () => {
  expect(playerCountLabel(0)).toBeNull();
});
