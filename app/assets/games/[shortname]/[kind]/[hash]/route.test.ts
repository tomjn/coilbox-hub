import { beforeEach, expect, mock, test } from "bun:test";

// `lib/games/art.test.ts` covers what `fetchGameArt` answers for a matching
// hash, a wrong one, a hidden game, an unrecognised staged tier and a promoted
// row. What is left to prove here is the route's own job: which requests reach
// it, the headers on a served picture and a redirect, and that every refusal
// looks the same.

mock.module("@/lib/supabase/anon", () => ({ createAnonClient: () => ({ role: "anon" }) }));
mock.module("@/lib/supabase/admin", () => ({ createAdminClient: () => ({ role: "admin" }) }));

let answer: () => Promise<unknown>;
const fetchGameArt = mock(() => answer());

mock.module("@/lib/games/art", () => ({ fetchGameArt }));

const { GET } = await import("./route");

const HASH = "a".repeat(64);

function ctx(shortname: string, kind: string, hash: string) {
  return { params: Promise.resolve({ shortname, kind, hash }) } as never;
}

const request = () => new Request("http://hub.test/x");

beforeEach(() => {
  fetchGameArt.mockClear();
});

test("staged art is served with headers that keep it an image and cache it for good", async () => {
  answer = () => Promise.resolve({ bytes: new Blob(["webp bytes"]), mime: "image/webp" });

  const response = await GET(request(), ctx("BA", "logo", HASH));

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("webp bytes");
  expect(response.headers.get("Content-Type")).toBe("image/webp");
  expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
  expect(response.headers.get("Content-Security-Policy")).toBe("default-src 'none'; sandbox");
  expect(response.headers.get("Cache-Control")).toBe("public, max-age=31536000, immutable");
  expect(response.headers.get("Access-Control-Allow-Origin")).toBe("*");
  expect(response.headers.get("Set-Cookie")).toBeNull();
  expect(fetchGameArt).toHaveBeenCalledWith({ role: "anon" }, { role: "admin" }, "BA", "logo", HASH);
});

test("promoted art is a temporary, uncached redirect to the durable tier and nothing else", async () => {
  answer = () => Promise.resolve({ promoted: "https://tomjn.github.io/coilbox-assets/games/BA/banner.webp" });

  const response = await GET(request(), ctx("BA", "banner", HASH));

  expect(response.status).toBe(307);
  expect(await response.text()).toBe("");
  expect([...response.headers].sort()).toEqual([
    ["access-control-allow-origin", "*"],
    ["cache-control", "no-store"],
    ["location", "https://tomjn.github.io/coilbox-assets/games/BA/banner.webp"],
  ]);
});

// Compared whole, so a header added to one refusal and not another would show.
test("every refusal is the same bodiless, uncached 404", async () => {
  answer = () => Promise.resolve(null);

  const responses = await Promise.all([
    GET(request(), ctx("BA", "logo", HASH)),
    GET(request(), ctx("HIDDEN", "banner", HASH)),
  ]);

  for (const response of responses) {
    expect(response.status).toBe(404);
    expect(await response.text()).toBe("");
    expect([...response.headers]).toEqual([["cache-control", "no-store"]]);
  }
});

test("a kind other than logo or banner, or a malformed hash, is refused without a read", async () => {
  answer = () => Promise.resolve({ bytes: new Blob(["never"]), mime: "image/webp" });

  const responses = await Promise.all([
    GET(request(), ctx("BA", "snippet", HASH)),
    GET(request(), ctx("BA", "logo", "A".repeat(64))),
    GET(request(), ctx("BA", "logo", "abc")),
  ]);

  for (const response of responses) {
    expect(response.status).toBe(404);
    expect([...response.headers]).toEqual([["cache-control", "no-store"]]);
  }
  expect(fetchGameArt).not.toHaveBeenCalled();
});

test("a database or storage failure is a clean, uncached 502", async () => {
  answer = () => Promise.reject(new Error("service unavailable"));

  const response = await GET(request(), ctx("BA", "logo", HASH));

  expect(response.status).toBe(502);
  expect(await response.text()).toBe("");
  expect(response.headers.get("Cache-Control")).toBe("no-store");
});
