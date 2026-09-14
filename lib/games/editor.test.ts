import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { editableGame } from "./editor";

/**
 * Who may change a game (#350). The database side, that the policies let a
 * moderator write and nobody else, is `supabase/tests/game_ownership.test.sql`.
 * What is proved here is the question every page, action and route asks before
 * it shows a form or spends the secret key.
 */

const OWNER = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";
const STRANGER = "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb";
const MODERATOR = "cccccccc-cccc-cccc-cccc-cccccccccccc";

const GAMES = [
  { id: "game-ba", shortname: "BA", owner_user_id: OWNER },
  { id: "game-zk", shortname: "ZK", owner_user_id: null },
];

/** A client that answers `is_moderator()` for one account and filters the
 *  game rows by the `eq` calls it is given, the way PostgREST would. */
function clientFor(userId: string, moderatorAnswer: { data: unknown; error: unknown } = {
  data: userId === MODERATOR,
  error: null,
}): SupabaseClient {
  return {
    rpc: async (name: string) => {
      if (name !== "is_moderator") throw new Error(`unexpected rpc ${name}`);
      return moderatorAnswer;
    },
    from: (table: string) => {
      if (table !== "game") throw new Error(`unexpected table ${table}`);
      const filters: [string, unknown][] = [];
      const query = {
        select: () => query,
        eq: (column: string, value: unknown) => {
          filters.push([column, value]);
          return query;
        },
        maybeSingle: async () => {
          const rows = GAMES.filter((row) =>
            filters.every(([column, value]) => row[column as keyof typeof row] === value),
          );
          return { data: rows[0] ? { id: rows[0].id } : null, error: null };
        },
      };
      return query;
    },
  } as unknown as SupabaseClient;
}

test("the owner may change their own game", async () => {
  expect(await editableGame(clientFor(OWNER), OWNER, "BA")).toEqual({ id: "game-ba" });
});

test("the owner may not change a game they do not own", async () => {
  expect(await editableGame(clientFor(OWNER), OWNER, "ZK")).toBeNull();
});

test("a moderator may change a game somebody else owns", async () => {
  expect(await editableGame(clientFor(MODERATOR), MODERATOR, "BA")).toEqual({ id: "game-ba" });
});

test("a moderator may change a game nobody owns", async () => {
  expect(await editableGame(clientFor(MODERATOR), MODERATOR, "ZK")).toEqual({ id: "game-zk" });
});

test("a signed in account that is neither owner nor moderator may change nothing", async () => {
  expect(await editableGame(clientFor(STRANGER), STRANGER, "BA")).toBeNull();
  expect(await editableGame(clientFor(STRANGER), STRANGER, "ZK")).toBeNull();
});

test("a moderator asking about a game that does not exist gets nothing", async () => {
  expect(await editableGame(clientFor(MODERATOR), MODERATOR, "NOPE")).toBeNull();
});

test("an is_moderator call that errors is not a yes", async () => {
  const failing = clientFor(STRANGER, { data: null, error: { message: "boom" } });
  expect(await editableGame(failing, STRANGER, "BA")).toBeNull();
});
