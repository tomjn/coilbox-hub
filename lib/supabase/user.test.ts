import { expect, mock, test } from "bun:test";

let claims: { sub: string } | null = null;
let rpcResult: { data: unknown; error: unknown } = { data: true, error: null };
const rpc = mock(async () => rpcResult);

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: { getClaims: async () => ({ data: claims ? { claims } : null }) },
    rpc,
  }),
}));

const { isModerator, userFromClaims } = await import("@/lib/supabase/user");

test("reads the id and the display metadata off a token's claims", () => {
  expect(userFromClaims({ sub: "abc", user_metadata: { full_name: "Ada" } })).toEqual({
    id: "abc",
    metadata: { full_name: "Ada" },
  });
});

test("a token without metadata still names its user", () => {
  expect(userFromClaims({ sub: "abc" })).toEqual({ id: "abc", metadata: {} });
});

test("a signed out visitor is not a moderator and the database is not asked", async () => {
  claims = null;
  rpc.mockClear();
  expect(await isModerator()).toBe(false);
  expect(rpc).not.toHaveBeenCalled();
});

test("a signed in moderator is a moderator", async () => {
  claims = { sub: "abc" };
  rpcResult = { data: true, error: null };
  expect(await isModerator()).toBe(true);
});

test("a signed in visitor the database says no to is not a moderator", async () => {
  claims = { sub: "abc" };
  rpcResult = { data: false, error: null };
  expect(await isModerator()).toBe(false);
});

test("an error from the database is not a yes", async () => {
  claims = { sub: "abc" };
  rpcResult = { data: null, error: { message: "boom" } };
  expect(await isModerator()).toBe(false);
});
