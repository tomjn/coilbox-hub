import { afterEach, expect, mock, spyOn, test } from "bun:test";

// The link preview for a game with no logo (#360): after a logo is removed,
// the row names no path, hash or staged copy, and the card must be drawn as
// text alone without asking the bucket or the durable tier for anything.

const page = {
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
};

mock.module("@/lib/games/cached", () => ({ gamePageCached: async () => page }));

const adminCreated = mock(() => ({}));
const anonCreated = mock(() => ({}));
mock.module("@/lib/supabase/admin", () => ({ createAdminClient: adminCreated }));
mock.module("@/lib/supabase/anon", () => ({ createAnonClient: anonCreated }));

const Image = (await import("./opengraph-image")).default;

afterEach(() => {
  mock.restore();
});

test("a game whose logo was removed gets a text only preview, and nothing is fetched for it", async () => {
  const fetched = spyOn(globalThis, "fetch");

  const response = await Image({ params: Promise.resolve({ shortname: "BA" }) });
  const bytes = await response.arrayBuffer();

  expect(response.headers.get("content-type")).toBe("image/png");
  expect(bytes.byteLength).toBeGreaterThan(0);
  // The renderer loads its own WebAssembly from a data URI. Nothing else may
  // go over the network.
  const urls = fetched.mock.calls.map((call) => String(call[0])).filter((url) => !url.startsWith("data:"));
  expect(urls).toEqual([]);
  expect(adminCreated).not.toHaveBeenCalled();
  expect(anonCreated).not.toHaveBeenCalled();
});
