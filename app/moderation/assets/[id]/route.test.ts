import { afterEach, beforeEach, expect, mock, test } from "bun:test";

// The route talks to three things this file replaces: the session client
// (`is_moderator`), the admin client (a constructor `fetchAssetObject` never
// actually calls once mocked), and `fetchAssetObject` itself, which
// `lib/assets/queue.test.ts` already covers for both tiers and the missing
// object case. What is left to prove here is the route's own job: turning
// what `fetchAssetObject` answers into a response, unchanged for a moderator
// and 404 for anybody who is not one.

let moderator = true;
const rpc = mock(() => Promise.resolve({ data: moderator, error: null }));

mock.module("@/lib/supabase/server", () => ({
  createClient: () => Promise.resolve({ rpc }),
}));

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({}),
}));

let fetchAssetObjectResult: unknown;
const fetchAssetObject = mock(() => fetchAssetObjectResult);

mock.module("@/lib/assets/queue", () => ({ fetchAssetObject }));

const { GET } = await import("./route");

function ctx(id: string) {
  return { params: Promise.resolve({ id }) } as never;
}

const ID = "0f8fad5b-d9cb-469f-a165-70867728950e";

beforeEach(() => {
  moderator = true;
  fetchAssetObject.mockClear();
  rpc.mockClear();
});

test("a moderator asking for a row that does not exist gets 404", async () => {
  fetchAssetObjectResult = Promise.resolve(null);

  const response = await GET(new Request("http://hub.test/x"), ctx(ID));

  expect(response.status).toBe(404);
});

test("somebody who is not a moderator gets 404 without the row ever being looked up", async () => {
  moderator = false;
  fetchAssetObjectResult = Promise.resolve({ source: "bytes", bytes: new Blob(["hi"]), mime: "image/webp" });

  const response = await GET(new Request("http://hub.test/x"), ctx(ID));

  expect(response.status).toBe(404);
  expect(fetchAssetObject).not.toHaveBeenCalled();
});

test("a bucket row is served to a moderator with the security headers unchanged", async () => {
  fetchAssetObjectResult = Promise.resolve({
    source: "bytes",
    bytes: new Blob(["hello"]),
    mime: "image/webp",
  });

  const response = await GET(new Request("http://hub.test/x"), ctx(ID));

  expect(response.status).toBe(200);
  expect(await response.text()).toBe("hello");
  expect(response.headers.get("Content-Type")).toBe("image/webp");
  expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
  expect(response.headers.get("Content-Security-Policy")).toBe("default-src 'none'; sandbox");
  expect(response.headers.get("Cache-Control")).toBe("private, max-age=300");
});

test("a static row is still fetched from its durable tier URL, with the same headers", async () => {
  fetchAssetObjectResult = Promise.resolve({
    source: "url",
    url: "https://tomjn.github.io/coilbox-assets/units/bar/abc.webp",
    mime: "image/webp",
  });
  const originalFetch = globalThis.fetch;
  const upstreamFetch = mock(() => Promise.resolve(new Response("durable bytes", { status: 200 })));
  globalThis.fetch = upstreamFetch as unknown as typeof fetch;

  try {
    const response = await GET(new Request("http://hub.test/x"), ctx(ID));

    expect(upstreamFetch).toHaveBeenCalledWith("https://tomjn.github.io/coilbox-assets/units/bar/abc.webp");
    expect(response.status).toBe(200);
    expect(await response.text()).toBe("durable bytes");
    expect(response.headers.get("Content-Type")).toBe("image/webp");
    expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
    expect(response.headers.get("Content-Security-Policy")).toBe("default-src 'none'; sandbox");
    expect(response.headers.get("Cache-Control")).toBe("private, max-age=300");
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test("a row whose declared MIME the hub does not store falls back to octet-stream", async () => {
  fetchAssetObjectResult = Promise.resolve({
    source: "bytes",
    bytes: new Blob(["hi"]),
    mime: "text/html",
  });

  const response = await GET(new Request("http://hub.test/x"), ctx(ID));

  expect(response.headers.get("Content-Type")).toBe("application/octet-stream");
});

test("a storage failure that is not a missing object is a clean 502, not a crash", async () => {
  fetchAssetObjectResult = Promise.reject(new Error("service unavailable"));

  const response = await GET(new Request("http://hub.test/x"), ctx(ID));

  expect(response.status).toBe(502);
  expect(await response.text()).toBe("");
});

afterEach(() => {
  fetchAssetObjectResult = undefined;
});
