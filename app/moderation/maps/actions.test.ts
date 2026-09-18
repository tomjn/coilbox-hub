import { beforeEach, expect, mock, test } from "bun:test";

// What `setMapFeatured` tells the form for each answer the database can give
// (#394). Who may write is a plain service_role update guarded by is_moderator,
// the same shape `setGameFeatured` (`app/games/actions.ts`) checks, so what is
// proved here is the translation from that check and from the write's result
// into a message, the same split `app/item/featured.test.ts` draws for
// `setItemFeatured`.

const MODERATOR = "cccccccc-cccc-cccc-cccc-cccccccccccc";
const MAP_ID = "0f8fad5b-0007-4000-8000-000000000042";

let visitor: string | null = MODERATOR;
let moderator = true;
let mapUpdateResult: { data: unknown; error: unknown } = { data: [{ id: MAP_ID }], error: null };

function visitorClient() {
  return {
    auth: { getUser: async () => ({ data: { user: visitor ? { id: visitor } : null }, error: null }) },
    rpc: async () => ({ data: moderator, error: null }),
  };
}

mock.module("@/lib/supabase/server", () => ({ createClient: async () => visitorClient() }));

function updateChain(result: () => { data: unknown; error: unknown }) {
  const chain = {
    eq: () => chain,
    select: async () => result(),
  };
  return chain;
}

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({
    from: (table: string) => {
      if (table === "map") return { update: () => updateChain(() => mapUpdateResult) };
      throw new Error(`unexpected table ${table}`);
    },
  }),
}));

const revalidated = mock<(path: string) => void>(() => {});
const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: revalidated, updateTag: mock(() => {}) }));

const { setMapFeatured } = await import("./actions");
const { MAP_FEATURED_MESSAGES } = await import("@/lib/maps/featured");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) data.set(key, value);
  return data;
}

beforeEach(() => {
  visitor = MODERATOR;
  moderator = true;
  mapUpdateResult = { data: [{ id: MAP_ID }], error: null };
  revalidated.mockClear();
});

test("a moderator featuring a map is told so and the catalog is revalidated", async () => {
  const state = await setMapFeatured(null, form({ id: MAP_ID, slug: "comet", featured: "true" }));

  expect(state).toEqual({ ok: true, message: MAP_FEATURED_MESSAGES.featured });
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual([
    "/moderation/maps",
    "/moderation/maps/comet",
  ]);
});

test("unfeaturing says the other thing", async () => {
  const state = await setMapFeatured(null, form({ id: MAP_ID, slug: "comet", featured: "false" }));

  expect(state).toEqual({ ok: true, message: MAP_FEATURED_MESSAGES.unfeatured });
});

test("a session without can_moderate is refused before any write is attempted", async () => {
  moderator = false;

  const state = await setMapFeatured(null, form({ id: MAP_ID, featured: "true" }));

  expect(state).toEqual({ ok: false, message: MAP_FEATURED_MESSAGES.notAllowed });
});

test("a signed out visitor is asked to sign in", async () => {
  visitor = null;

  const state = await setMapFeatured(null, form({ id: MAP_ID, featured: "true" }));

  expect(state).toEqual({ ok: false, message: MAP_FEATURED_MESSAGES.signedOut });
});

test("an id that is not a uuid never reaches the database", async () => {
  const state = await setMapFeatured(null, form({ id: "not-a-uuid", featured: "true" }));

  expect(state).toEqual({ ok: false, message: MAP_FEATURED_MESSAGES.notSent });
});

test("a map that no longer exists is reported as not found", async () => {
  mapUpdateResult = { data: [], error: null };

  const state = await setMapFeatured(null, form({ id: MAP_ID, featured: "true" }));

  expect(state).toEqual({ ok: false, message: MAP_FEATURED_MESSAGES.notFound });
});

test("a write the database refuses is reported as not saved", async () => {
  mapUpdateResult = { data: null, error: new Error("db down") };

  const state = await setMapFeatured(null, form({ id: MAP_ID, featured: "true" }));

  expect(state).toEqual({ ok: false, message: MAP_FEATURED_MESSAGES.notSaved });
});
