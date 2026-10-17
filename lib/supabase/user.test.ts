import { expect, mock, test } from "bun:test";

let claims: { sub: string } | null = null;
let rpcResult: { data: unknown; error: unknown } = { data: true, error: null };
const rpc = mock(async () => rpcResult);
const from = mock(() => {
  const builder = {
    select: () => builder,
    eq: () => builder,
    maybeSingle: async () => ({ data: { display_name: "Ada" }, error: null }),
  };
  return builder;
});

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: { getClaims: async () => ({ data: claims ? { claims } : null }) },
    rpc,
    from,
  }),
}));

const { currentDisplayName, isModerator, userFromClaims } = await import("@/lib/supabase/user");

test("reads the id off a token's claims, and nothing the provider wrote", () => {
  expect(userFromClaims({ sub: "abc" })).toEqual({ id: "abc" });
});

test("a signed out visitor has no display name and the database is not asked", async () => {
  claims = null;
  from.mockClear();
  expect(await currentDisplayName()).toBeNull();
  expect(from).not.toHaveBeenCalled();
});

test("a signed in visitor's display name comes from their profile", async () => {
  claims = { sub: "abc" };
  expect(await currentDisplayName()).toBe("Ada");
  expect(from).toHaveBeenCalledWith("profile");
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
