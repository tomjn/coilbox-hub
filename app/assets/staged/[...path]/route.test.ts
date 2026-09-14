import { beforeEach, expect, mock, test } from "bun:test";

// The route talks to two client constructors and to `fetchApprovedStagedPicture`,
// which `lib/assets/bucket.test.ts` covers for the approved filter, the missing
// object and a broken store. What is left to prove here is the route's own job:
// the path it asks for, the headers on a served picture, and that every refusal
// looks the same.

mock.module("@/lib/supabase/anon", () => ({ createAnonClient: () => ({ role: "anon" }) }));
mock.module("@/lib/supabase/admin", () => ({ createAdminClient: () => ({ role: "admin" }) }));

let answer: () => Promise<unknown>;
const fetchApprovedStagedPicture = mock(() => answer());

mock.module("@/lib/assets/bucket", () => ({ fetchApprovedStagedPicture }));

const { GET } = await import("./route");

function ctx(path: string[]) {
  return { params: Promise.resolve({ path }) } as never;
}

const SEGMENTS = ["units", "bar", "buildpic", "abc.webp"];

beforeEach(() => {
  fetchApprovedStagedPicture.mockClear();
});

test("an approved picture is served with headers that keep it an image and cache it for good", async () => {
  answer = () => Promise.resolve({ bytes: new Blob(["webp bytes"]), mime: "image/webp" });

  const response = await GET(new Request("http://hub.test/x"), ctx(SEGMENTS));

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("webp bytes");
  expect(response.headers.get("Content-Type")).toBe("image/webp");
  expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
  expect(response.headers.get("Content-Security-Policy")).toBe("default-src 'none'; sandbox");
  expect(response.headers.get("Cache-Control")).toBe("public, max-age=31536000, immutable");
  expect(response.headers.get("Access-Control-Allow-Origin")).toBe("*");
  expect(response.headers.get("Set-Cookie")).toBeNull();
});

test("the path is the segments joined, read as anon for the row and admin for the bytes", async () => {
  answer = () => Promise.resolve(null);

  await GET(new Request("http://hub.test/x"), ctx(SEGMENTS));

  expect(fetchApprovedStagedPicture).toHaveBeenCalledWith(
    { role: "anon" },
    { role: "admin" },
    "units/bar/buildpic/abc.webp",
  );
});

test("a row whose declared type the hub does not store falls back to octet-stream", async () => {
  answer = () => Promise.resolve({ bytes: new Blob(["<script>"]), mime: "text/html" });

  const response = await GET(new Request("http://hub.test/x"), ctx(SEGMENTS));

  expect(response.headers.get("Content-Type")).toBe("application/octet-stream");
});

// `fetchApprovedStagedPicture` answers null for a pending row, a rejected row,
// a path nothing names and an approved row with no object, so they are one
// response. Compared whole, so a header added to one refusal and not another
// would show here.
test("every refusal is the same bodiless, uncached 404", async () => {
  answer = () => Promise.resolve(null);

  const responses = await Promise.all(
    [SEGMENTS, ["maps", "minimap", "nothing.webp"], ["..", "etc", "passwd"]].map((path) =>
      GET(new Request("http://hub.test/x"), ctx(path)),
    ),
  );

  for (const response of responses) {
    expect(response.status).toBe(404);
    expect(await response.text()).toBe("");
    expect([...response.headers]).toEqual([["cache-control", "no-store"]]);
  }
});

test("a database or storage failure is a clean, uncached 502", async () => {
  answer = () => Promise.reject(new Error("service unavailable"));

  const response = await GET(new Request("http://hub.test/x"), ctx(SEGMENTS));

  expect(response.status).toBe(502);
  expect(await response.text()).toBe("");
  expect(response.headers.get("Cache-Control")).toBe("no-store");
});
