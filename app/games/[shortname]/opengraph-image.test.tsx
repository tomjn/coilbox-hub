import { afterEach, expect, mock, spyOn, test } from "bun:test";

// The link preview for a game with no logo (#360): after a logo is removed,
// the row names no path, hash or staged copy, and the card must be drawn as
// text alone without asking the bucket or the durable tier for anything.
//
// #366's WebP cases below reuse the real libwebp output frozen in
// lib/assets/imageHeader.test.ts, rather than writing WebP bytes by hand.

/** 3x2, lossy, no alpha - the same frozen libwebp output as
 *  `lib/assets/imageHeader.test.ts`'s `lossyWebp` sample. */
const WEBP_BYTES = Buffer.from(
  "UklGRjoAAABXRUJQVlA4IC4AAAAQAgCdASoDAAIAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA",
  "base64",
);

/** 2x2, 8 bit RGB - the same frozen libpng output as
 *  `lib/assets/imageHeader.test.ts`'s `rgb8Png` sample. */
const PNG_BYTES = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC",
  "base64",
);

function page(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    shortname: "BA",
    display_name: "Balanced Annihilation",
    description: null,
    links: [],
    owner_user_id: null,
    hidden_at: null,
    logo_path: null,
    logo_hash: null,
    logo_staged_tier: null,
    banner_path: null,
    banner_hash: null,
    banner_staged_tier: null,
    factions: [],
    versions: [],
    faction_count: 2,
    unit_count: 340,
    item_count: 0,
    ...overrides,
  };
}

let currentPage = page();
mock.module("@/lib/games/cached", () => ({ gamePageCached: async () => currentPage }));

const adminCreated = mock(() => ({}));
const anonCreated = mock(() => ({}));
mock.module("@/lib/supabase/admin", () => ({ createAdminClient: adminCreated }));
mock.module("@/lib/supabase/anon", () => ({ createAnonClient: anonCreated }));

const Image = (await import("./opengraph-image")).default;

afterEach(() => {
  mock.restore();
  currentPage = page();
});

async function render() {
  const response = await Image({ params: Promise.resolve({ shortname: "BA" }) });
  return { response, bytes: await response.arrayBuffer() };
}

test("a game whose logo was removed gets a text only preview, and nothing is fetched for it", async () => {
  const fetched = spyOn(globalThis, "fetch");

  const { response, bytes } = await render();

  expect(response.headers.get("content-type")).toBe("image/png");
  expect(bytes.byteLength).toBeGreaterThan(0);
  // The renderer loads its own WebAssembly from a data URI. Nothing else may
  // go over the network.
  const urls = fetched.mock.calls.map((call) => String(call[0])).filter((url) => !url.startsWith("data:"));
  expect(urls).toEqual([]);
  expect(adminCreated).not.toHaveBeenCalled();
  expect(anonCreated).not.toHaveBeenCalled();
});

test("a WebP logo still staged in the bucket gets a text only preview, not a 500 (#366)", async () => {
  mock.module("@/lib/games/art", () => ({
    fetchGameArt: mock(async () => ({ bytes: new Blob([WEBP_BYTES]), mime: "image/webp" })),
    gameArtUrl: mock(() => null),
  }));
  currentPage = page({ logo_path: "games/BA/logo.webp", logo_hash: "deadbeef", logo_staged_tier: "bucket" });

  const { response, bytes } = await render();

  expect(response.headers.get("content-type")).toBe("image/png");
  expect(bytes.byteLength).toBeGreaterThan(0);
});

test("a WebP logo already promoted to the durable tier gets a text only preview, not a 500 (#366)", async () => {
  spyOn(globalThis, "fetch").mockResolvedValue(
    new Response(WEBP_BYTES, { status: 200, headers: { "content-type": "image/webp" } }),
  );
  currentPage = page({ logo_path: "games/BA/logo.webp", logo_hash: "deadbeef", logo_staged_tier: null });

  const { response, bytes } = await render();

  expect(response.headers.get("content-type")).toBe("image/png");
  expect(bytes.byteLength).toBeGreaterThan(0);
});

test("a PNG logo still staged in the bucket is drawn into the preview", async () => {
  const fetchArt = mock(async () => ({ bytes: new Blob([PNG_BYTES]), mime: "image/png" }));
  mock.module("@/lib/games/art", () => ({
    fetchGameArt: fetchArt,
    gameArtUrl: mock(() => null),
  }));
  currentPage = page({ logo_path: "games/BA/logo.png", logo_hash: "deadbeef", logo_staged_tier: "bucket" });

  const { response, bytes } = await render();

  expect(response.headers.get("content-type")).toBe("image/png");
  expect(bytes.byteLength).toBeGreaterThan(0);
  expect(fetchArt).toHaveBeenCalledTimes(1);
});
