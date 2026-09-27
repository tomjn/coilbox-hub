import { beforeEach, expect, mock, test } from "bun:test";

// What the map pack actions tell the form for each answer the database can give
// (tomjn/coilbox#3206). Who may write is proved in map_pack.test.sql. What is
// proved here is the moderator check in front of every write, and the
// translation of each result into a message.

const MODERATOR = "cccccccc-cccc-cccc-cccc-cccccccccccc";
const PACK_ID = "9e0f0000-0000-4000-8000-000000000001";

let visitor: string | null = MODERATOR;
let moderator = true;
let updateResult: { data: unknown; error: unknown } = { data: [{ id: PACK_ID }], error: null };
let insertResult: { data: unknown; error: unknown } = { data: { id: PACK_ID }, error: null };
let rpcResult: { data: unknown; error: unknown } = { data: [], error: null };
const writes: { table: string; op: string; values?: unknown }[] = [];
const rpcCalls: { name: string; args: unknown }[] = [];

function visitorClient() {
  return {
    auth: { getUser: async () => ({ data: { user: visitor ? { id: visitor } : null }, error: null }) },
    rpc: async () => ({ data: moderator, error: null }),
  };
}

mock.module("@/lib/supabase/server", () => ({ createClient: async () => visitorClient() }));

function chain(result: () => { data: unknown; error: unknown }) {
  const self = {
    eq: () => self,
    select: () => self,
    single: async () => result(),
    then: (resolve: (value: unknown) => unknown) => resolve(result()),
  };
  return self;
}

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({
    from: (table: string) => ({
      update: (values: unknown) => {
        writes.push({ table, op: "update", values });
        return chain(() => updateResult);
      },
      insert: (values: unknown) => {
        writes.push({ table, op: "insert", values });
        return chain(() => insertResult);
      },
      delete: () => {
        writes.push({ table, op: "delete" });
        return chain(() => ({ data: null, error: null }));
      },
    }),
    rpc: async (name: string, args: unknown) => {
      rpcCalls.push({ name, args });
      return rpcResult;
    },
  }),
}));

class Redirected extends Error {}
const redirected = mock<(path: string) => never>((path: string) => {
  throw new Redirected(path);
});
mock.module("next/navigation", () => ({ redirect: redirected }));

const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: mock(() => {}), updateTag: mock(() => {}) }));

const { addMapsToPack, createMapPack, removeMapFromPack, saveMapPack, setMapPackFeatured } =
  await import("./actions");
const { MAP_PACK_MESSAGES } = await import("@/lib/maps/packs");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) data.set(key, value);
  return data;
}

beforeEach(() => {
  visitor = MODERATOR;
  moderator = true;
  updateResult = { data: [{ id: PACK_ID }], error: null };
  insertResult = { data: { id: PACK_ID }, error: null };
  rpcResult = { data: [], error: null };
  writes.length = 0;
  rpcCalls.length = 0;
  redirected.mockClear();
});

test("featuring a pack records who did it", async () => {
  const state = await setMapPackFeatured(null, form({ id: PACK_ID, featured: "true" }));

  expect(state).toEqual({ ok: true, message: MAP_PACK_MESSAGES.featured });
  expect(writes[0].values).toMatchObject({ featured_by: MODERATOR });
});

test("unfeaturing clears both columns", async () => {
  const state = await setMapPackFeatured(null, form({ id: PACK_ID, featured: "false" }));

  expect(state).toEqual({ ok: true, message: MAP_PACK_MESSAGES.unfeatured });
  expect(writes[0].values).toEqual({ featured_at: null, featured_by: null });
});

test("a session without can_moderate is refused before any write", async () => {
  moderator = false;

  expect(await setMapPackFeatured(null, form({ id: PACK_ID, featured: "true" }))).toEqual({
    ok: false,
    message: MAP_PACK_MESSAGES.notAllowed,
  });
  expect(await addMapsToPack(null, form({ id: PACK_ID, names: "Isis 1.3" }))).toEqual({
    ok: false,
    message: MAP_PACK_MESSAGES.notAllowed,
  });
  expect(await removeMapFromPack(null, form({ id: PACK_ID, map: "Isis 1.3" }))).toEqual({
    ok: false,
    message: MAP_PACK_MESSAGES.notAllowed,
  });
  expect(writes).toEqual([]);
  expect(rpcCalls).toEqual([]);
});

test("a signed out visitor is asked to sign in", async () => {
  visitor = null;

  const state = await saveMapPack(null, form({ id: PACK_ID, title: "BAR maps" }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.signedOut });
});

test("an id that is not a uuid never reaches the database", async () => {
  const state = await addMapsToPack(null, form({ id: "nope", names: "Isis 1.3" }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.notSent });
  expect(rpcCalls).toEqual([]);
});

test("a pasted list goes to add_maps_to_pack as trimmed lines, and misses are named", async () => {
  rpcResult = {
    data: [
      { wanted: "isis_1.3.sd7", map_name: "Isis 1.3" },
      { wanted: "Nowhere 9", map_name: null },
    ],
    error: null,
  };

  const state = await addMapsToPack(null, form({ id: PACK_ID, names: " isis_1.3.sd7 \n\nNowhere 9\n" }));

  expect(rpcCalls).toEqual([
    { name: "add_maps_to_pack", args: { p_pack_id: PACK_ID, p_names: ["isis_1.3.sd7", "Nowhere 9"] } },
  ]);
  expect(state).toEqual({ ok: true, message: "1 line matched a map. No map is called: Nowhere 9." });
});

test("an empty paste is refused without a call", async () => {
  const state = await addMapsToPack(null, form({ id: PACK_ID, names: " \n " }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.nothingPasted });
  expect(rpcCalls).toEqual([]);
});

test("adding to a pack deleted under the form says it is gone", async () => {
  rpcResult = { data: null, error: { code: "23503", message: "fk" } };

  const state = await addMapsToPack(null, form({ id: PACK_ID, names: "Isis 1.3" }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.notFound });
});

test("saving a pack that no longer exists is reported as not found", async () => {
  updateResult = { data: [], error: null };

  const state = await saveMapPack(null, form({ id: PACK_ID, title: "BAR maps", blurb: "" }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.notFound });
});

test("a blank title is refused before anything is asked", async () => {
  const state = await createMapPack(null, form({ title: "   " }));

  expect(state).toEqual({ ok: false, message: MAP_PACK_MESSAGES.badText });
  expect(writes).toEqual([]);
});

test("starting a pack goes to its page", async () => {
  await expect(createMapPack(null, form({ title: "BAR maps" }))).rejects.toThrow(Redirected);

  expect(writes[0]).toEqual({ table: "map_pack", op: "insert", values: { title: "BAR maps", blurb: null } });
  expect(redirected.mock.calls[0][0]).toBe(`/moderation/map-packs/${PACK_ID}`);
});
