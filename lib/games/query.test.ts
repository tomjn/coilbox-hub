import { expect, test } from "bun:test";
import { fetchGames } from "./query";

/**
 * The listing's read (#225): what it orders by, and that a database error
 * arrives as an answer rather than as a thrown thing the page cannot render.
 */

function row(shortname: string, unitCount: number): Record<string, unknown> {
  return {
    shortname,
    display_name: null,
    description: null,
    faction_count: 1,
    unit_count: unitCount,
  };
}

function fakeSupabase(data: unknown, error: unknown = null) {
  return {
    from: () => ({
      select: () =>
        Promise.resolve({ data, error } as unknown as {
          data: unknown;
          error: unknown;
        }),
    }),
  };
}

test("games order biggest first, ties broken alphabetically", async () => {
  const { games } = await fetchGames(fakeSupabase([row("XTA", 120), row("BA", 340), row("FF", 340)]) as never);
  expect(games.map((game) => game.shortname)).toEqual(["BA", "FF", "XTA"]);
});

test("a catalog read that fails comes back empty with the reason", async () => {
  const { games, error } = await fetchGames(fakeSupabase(null, { message: "nope" }) as never);
  expect(games).toEqual([]);
  expect(error).toBe("nope");
});
