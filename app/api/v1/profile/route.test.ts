import { beforeEach, expect, mock, test } from "bun:test";

// The signed in account's display name for the desktop client (#439). Bearer
// authentication and the profile read are replaced. What is under test is the
// status codes and that nothing shared may cache the answer.

const USER = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";

let auth: { ok: true } | { ok: false; reason: "missing" | "invalid" } = { ok: true };
let row: { display_name: string } | null = { display_name: "Tom" };
let askedFor: unknown = null;

const real = await import("@/lib/supabase/bearer");
mock.module("@/lib/supabase/bearer", () => ({
  ...real,
  authenticateBearer: async () =>
    auth.ok
      ? {
          ok: true,
          user: { id: USER },
          supabase: {
            from: () => {
              const query = {
                select: () => query,
                eq: (_column: string, value: unknown) => {
                  askedFor = value;
                  return query;
                },
                maybeSingle: async () => ({ data: row, error: null }),
              };
              return query;
            },
          },
        }
      : auth,
}));

const { GET, OPTIONS } = await import("./route");

beforeEach(() => {
  auth = { ok: true };
  row = { display_name: "Tom" };
  askedFor = null;
});

test("returns the signed in account's display name, uncacheable by anything shared", async () => {
  const response = await GET(new Request("http://hub.test/api/v1/profile"));

  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ display_name: "Tom" });
  expect(askedFor).toBe(USER);
  expect(response.headers.get("Cache-Control")).toBe("private, no-store");
  expect(response.headers.get("Access-Control-Allow-Origin")).toBe("*");
});

test("returns a null display name when the account has no profile row", async () => {
  row = null;

  const response = await GET(new Request("http://hub.test/api/v1/profile"));

  expect(response.status).toBe(200);
  expect(await response.json()).toEqual({ display_name: null });
});

test("a missing token is a 401 that tells the client what to send", async () => {
  auth = { ok: false, reason: "missing" };

  const response = await GET(new Request("http://hub.test/api/v1/profile"));

  expect(response.status).toBe(401);
  expect((await response.json()).error).toContain("Authorization: Bearer");
});

test("an invalid token is a 401", async () => {
  auth = { ok: false, reason: "invalid" };

  const response = await GET(new Request("http://hub.test/api/v1/profile"));

  expect(response.status).toBe(401);
  expect((await response.json()).error).toContain("not valid");
});

test("answers the CORS preflight", () => {
  expect(OPTIONS().status).toBe(204);
});
