import { expect, mock, test } from "bun:test";

const jar = new Map<string, { value: string; options: unknown }>();
mock.module("next/headers", () => ({
  cookies: async () => ({
    set: (name: string, value: string, options: unknown) => jar.set(name, { value, options }),
    delete: (name: string) => jar.delete(name),
  }),
}));

const { clearNav, readNav, writeNav } = await import("./nav");
const { NAV_COOKIE, parseNav } = await import("./navCookie");

function client({
  sub,
  name = "Ada",
  access = { data: "granted", error: null },
}: {
  sub: string | null;
  name?: string | null;
  access?: { data: unknown; error: unknown };
}) {
  const builder = {
    select: () => builder,
    eq: () => builder,
    maybeSingle: async () => ({ data: name === null ? null : { display_name: name }, error: null }),
  };
  return {
    auth: { getClaims: async () => ({ data: sub ? { claims: { sub } } : null }) },
    from: () => builder,
    rpc: mock(async () => access),
  } as never;
}

test("nobody signed in reads as nothing", async () => {
  expect(await readNav(client({ sub: null }))).toBeNull();
});

test("a signed in visitor reads as their id, name and moderation access", async () => {
  expect(await readNav(client({ sub: "abc" }))).toEqual({ id: "abc", name: "Ada", moderation: "granted" });
});

test("a failed access check is no moderation, and a missing profile is no name", async () => {
  const nav = await readNav(client({ sub: "abc", name: null, access: { data: null, error: { message: "x" } } }));
  expect(nav).toEqual({ id: "abc", name: null, moderation: "none" });
});

test("writing sets a browser readable cookie that parses back", async () => {
  jar.clear();
  await writeNav(client({ sub: "abc", access: { data: "verify", error: null } }));
  const written = jar.get(NAV_COOKIE);
  expect(parseNav(written?.value)).toEqual({ id: "abc", name: "Ada", moderation: "verify" });
  expect(written?.options).toMatchObject({ httpOnly: false, path: "/" });
});

test("writing with no session clears the cookie, and so does clearing", async () => {
  jar.set(NAV_COOKIE, { value: "x", options: {} });
  await writeNav(client({ sub: null }));
  expect(jar.has(NAV_COOKIE)).toBe(false);
  jar.set(NAV_COOKIE, { value: "x", options: {} });
  await clearNav();
  expect(jar.has(NAV_COOKIE)).toBe(false);
});
