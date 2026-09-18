import { expect, mock, test } from "bun:test";
import { ITEM_FEATURED_MESSAGES } from "@/lib/gallery/featured";

// What `setItemFeatured` tells the form for each answer the database can give.
//
// Who may write is proved against real roles in
// `supabase/tests/item_featured.test.sql`: the column grant refuses an author,
// and `public.set_item_featured` refuses a session without can_moderate. What
// is left here is the translation, and the one that matters is the refusal. A
// moderator whose capability was taken away while the page sat open has to be
// told that, not shown "try again in a few minutes" for a write that will
// never succeed however many times they try it.

let user: { id: string } | null = { id: "moderator-1" };
let rpcResult: { data: unknown; error: unknown } = { data: true, error: null };

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: { getUser: async () => ({ data: { user }, error: null }) },
    rpc: async () => rpcResult,
  }),
}));

const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, updateTag: mock(() => {}) }));

const { setItemFeatured } = await import("./actions");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [name, value] of Object.entries(fields)) data.set(name, value);
  return data;
}

const ITEM = "11111111-1111-1111-1111-111111111111";

test("featuring an item says so", async () => {
  user = { id: "moderator-1" };
  rpcResult = { data: true, error: null };

  const state = await setItemFeatured(null, form({ id: ITEM, featured: "true" }));

  expect(state).toEqual({ ok: true, message: ITEM_FEATURED_MESSAGES.featured });
});

test("unfeaturing says the other thing", async () => {
  rpcResult = { data: true, error: null };

  const state = await setItemFeatured(null, form({ id: ITEM, featured: "false" }));

  expect(state).toEqual({ ok: true, message: ITEM_FEATURED_MESSAGES.unfeatured });
});

test("a session without can_moderate is told what it is missing, not to try again", async () => {
  rpcResult = {
    data: null,
    error: { code: "42501", message: "featuring a gallery item needs can_moderate" },
  };

  const state = await setItemFeatured(null, form({ id: ITEM, featured: "true" }));

  expect(state).toEqual({ ok: false, message: ITEM_FEATURED_MESSAGES.notAllowed });
});

/** The function returns false rather than raising when it wrote nothing, which
 *  for a moderator pressing a button on an item in front of them means the
 *  item was withdrawn or removed while they were reading it. */
test("a write that changed nothing is not reported as a save", async () => {
  rpcResult = { data: false, error: null };

  const state = await setItemFeatured(null, form({ id: ITEM, featured: "true" }));

  expect(state).toEqual({ ok: false, message: ITEM_FEATURED_MESSAGES.nothingChanged });
});

test("a signed out visitor is asked to sign in", async () => {
  user = null;
  rpcResult = { data: true, error: null };

  const state = await setItemFeatured(null, form({ id: ITEM, featured: "true" }));

  expect(state).toEqual({ ok: false, message: ITEM_FEATURED_MESSAGES.signedOut });
});

test("a form with no item is refused before anything is asked of the database", async () => {
  user = { id: "moderator-1" };

  const state = await setItemFeatured(null, form({ featured: "true" }));

  expect(state).toEqual({ ok: false, message: ITEM_FEATURED_MESSAGES.notSent });
});
