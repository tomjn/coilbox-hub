import { beforeEach, expect, mock, test } from "bun:test";

// Before #362, `editGameDetails`, `setSnippet`, `setGameVisibility` and
// `setVersionVisibility` returned nothing whether they wrote a row or not, so
// a refused or failed save read exactly like a successful one: the form just
// sat there. Each now answers with a message, the way an image upload already
// does (#354, `app/games/editing.test.ts`).
//
// Who may write is proved elsewhere - `lib/games/editor.test.ts` for
// `editableGame`, `supabase/tests/game_ownership.test.sql` for the policies
// `editGameDetails` and `setSnippet` lean on. What is proved here is what each
// action tells the form for every one of those outcomes.

const OWNER = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";

const GAME = { id: "game-ba" };

let visitor: string | null = OWNER;
let moderator = false;

// What the visitor's own client answers when `editGameDetails` or `setSnippet`
// writes through row level security, and what the `game` lookup both of them
// (and `editableGame`, for the two visibility actions) make first.
let gameLookup: { id: string } | null = GAME;
let gameUpdateResult: { data: unknown; error: unknown } = { data: [{ shortname: "BA" }], error: null };
let unitUpdateResult: { data: unknown; error: unknown } = { data: [{ unit_name: "infantry" }], error: null };

function updateChain(result: () => { data: unknown; error: unknown }) {
  const chain = {
    eq: () => chain,
    select: async () => result(),
  };
  return chain;
}

function visitorClient() {
  return {
    auth: { getUser: async () => ({ data: { user: visitor ? { id: visitor } : null }, error: null }) },
    rpc: async () => ({ data: moderator, error: null }),
    from: (table: string) => {
      if (table === "game") {
        return {
          select: () => {
            const query = {
              eq: () => query,
              maybeSingle: async () => ({ data: gameLookup, error: null }),
            };
            return query;
          },
          update: () => updateChain(() => gameUpdateResult),
        };
      }
      if (table === "game_unit") {
        return { update: () => updateChain(() => unitUpdateResult) };
      }
      throw new Error(`unexpected table ${table}`);
    },
  };
}

mock.module("@/lib/supabase/server", () => ({ createClient: async () => visitorClient() }));

// The secret key client, for `setGameVisibility` and `setVersionVisibility`.
// Both check with the visitor's own client first (`editableGame`, above) and
// only spend this once that comes back with a game.
let adminAvailable = true;
let gameAdminResult: { error: unknown } = { error: null };
let versionAdminResult: { error: unknown } = { error: null };

function adminChain(result: () => { error: unknown }) {
  const chain = {
    eq: () => chain,
    then: (resolve: (value: unknown) => void) => resolve(result()),
  };
  return chain;
}

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => {
    if (!adminAvailable) throw new Error("no secret key");
    return {
      from: (table: string) => {
        if (table === "game") return { update: () => adminChain(() => gameAdminResult) };
        if (table === "game_version") return { update: () => adminChain(() => versionAdminResult) };
        throw new Error(`unexpected table ${table}`);
      },
    };
  },
}));

const revalidated = mock<(path: string) => void>(() => {});
const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: revalidated, updateTag: mock(() => {}) }));

const { editConquestFactions, editGameDetails, setSnippet, setGameVisibility, setVersionVisibility } =
  await import("./actions");
const { CONQUEST_FACTIONS_MESSAGES, EDIT_MESSAGES, SNIPPET_MESSAGES, VISIBILITY_MESSAGES } =
  await import("@/lib/games/formState");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) data.set(key, value);
  return data;
}

beforeEach(() => {
  visitor = OWNER;
  moderator = false;
  gameLookup = GAME;
  gameUpdateResult = { data: [{ shortname: "BA" }], error: null };
  unitUpdateResult = { data: [{ unit_name: "infantry" }], error: null };
  adminAvailable = true;
  gameAdminResult = { error: null };
  versionAdminResult = { error: null };
  revalidated.mockClear();
});

test("a saved edit tells the form and revalidates the game", async () => {
  const state = await editGameDetails(null, form({ shortname: "BA", display_name: "Blood Angels" }));

  expect(state).toEqual({ ok: true, message: EDIT_MESSAGES.saved });
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual(["/games", "/games/BA"]);
});

test("a signed out edit says so and writes nothing", async () => {
  visitor = null;

  expect(await editGameDetails(null, form({ shortname: "BA" }))).toEqual({
    ok: false,
    message: EDIT_MESSAGES.signedOut,
  });
  expect(revalidated).not.toHaveBeenCalled();
});

test("an edit the ownership policy filters out to zero rows is refused", async () => {
  gameUpdateResult = { data: [], error: null };

  expect(await editGameDetails(null, form({ shortname: "BA" }))).toEqual({
    ok: false,
    message: EDIT_MESSAGES.notAllowed,
  });
});

test("a write the database refuses is reported as not saved", async () => {
  gameUpdateResult = { data: null, error: new Error("db down") };

  expect(await editGameDetails(null, form({ shortname: "BA" }))).toEqual({
    ok: false,
    message: EDIT_MESSAGES.notSaved,
  });
});

test("an edit form with no shortname never reaches the write", async () => {
  expect(await editGameDetails(null, form({}))).toEqual({ ok: false, message: EDIT_MESSAGES.notSent });
});

test("a saved faction list tells the form and revalidates the game", async () => {
  const factions = JSON.stringify([
    { name: "House Arm-1", side: "ARM" },
    { name: "House Arm-2", side: "ARM" },
  ]);

  const state = await editConquestFactions(null, form({ shortname: "BA", factions }));

  expect(state).toEqual({ ok: true, message: CONQUEST_FACTIONS_MESSAGES.saved });
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual(["/games/BA", "/games/BA/edit"]);
});

test("a signed out faction save says so and writes nothing", async () => {
  visitor = null;

  expect(await editConquestFactions(null, form({ shortname: "BA", factions: "[]" }))).toEqual({
    ok: false,
    message: CONQUEST_FACTIONS_MESSAGES.signedOut,
  });
  expect(revalidated).not.toHaveBeenCalled();
});

test("a faction save the ownership policy filters out to zero rows is refused", async () => {
  gameUpdateResult = { data: [], error: null };

  expect(await editConquestFactions(null, form({ shortname: "BA", factions: "[]" }))).toEqual({
    ok: false,
    message: CONQUEST_FACTIONS_MESSAGES.notAllowed,
  });
});

test("a faction write the database refuses is reported as not saved", async () => {
  gameUpdateResult = { data: null, error: new Error("db down") };

  expect(await editConquestFactions(null, form({ shortname: "BA", factions: "[]" }))).toEqual({
    ok: false,
    message: CONQUEST_FACTIONS_MESSAGES.notSaved,
  });
});

test("a faction form with no shortname never reaches the write", async () => {
  expect(await editConquestFactions(null, form({ factions: "[]" }))).toEqual({
    ok: false,
    message: CONQUEST_FACTIONS_MESSAGES.notSent,
  });
});

test("factions that fail to parse are treated as an empty list rather than a crash", async () => {
  const state = await editConquestFactions(null, form({ shortname: "BA", factions: "not json" }));

  expect(state).toEqual({ ok: true, message: CONQUEST_FACTIONS_MESSAGES.saved });
});

test("a saved snippet tells the form", async () => {
  const state = await setSnippet(null, form({ shortname: "BA", unit_name: "infantry", snippet: "Elite." }));

  expect(state).toEqual({ ok: true, message: SNIPPET_MESSAGES.saved });
  expect(revalidated).toHaveBeenCalledWith("/games/BA/units/infantry");
});

test("a signed out snippet save says so", async () => {
  visitor = null;

  expect(await setSnippet(null, form({ shortname: "BA", unit_name: "infantry" }))).toEqual({
    ok: false,
    message: SNIPPET_MESSAGES.signedOut,
  });
});

test("a snippet for a game that no longer exists is refused", async () => {
  gameLookup = null;

  expect(await setSnippet(null, form({ shortname: "BA", unit_name: "infantry" }))).toEqual({
    ok: false,
    message: SNIPPET_MESSAGES.notAllowed,
  });
});

test("a snippet write the ownership policy filters out to zero rows is refused", async () => {
  unitUpdateResult = { data: [], error: null };

  expect(await setSnippet(null, form({ shortname: "BA", unit_name: "infantry" }))).toEqual({
    ok: false,
    message: SNIPPET_MESSAGES.notAllowed,
  });
});

test("a snippet write the database refuses is reported as not saved", async () => {
  unitUpdateResult = { data: null, error: new Error("db down") };

  expect(await setSnippet(null, form({ shortname: "BA", unit_name: "infantry" }))).toEqual({
    ok: false,
    message: SNIPPET_MESSAGES.notSaved,
  });
});

test("a snippet form missing the unit never reaches the write", async () => {
  expect(await setSnippet(null, form({ shortname: "BA" }))).toEqual({ ok: false, message: SNIPPET_MESSAGES.notSent });
});

test("hiding a game tells the form", async () => {
  const state = await setGameVisibility(null, form({ shortname: "BA", hidden: "true" }));

  expect(state).toEqual({ ok: true, message: "Game hidden." });
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual(["/games", "/games/BA", "/moderation/games"]);
});

test("showing a game again tells the form", async () => {
  expect(await setGameVisibility(null, form({ shortname: "BA", hidden: "false" }))).toEqual({
    ok: true,
    message: "Game shown again.",
  });
});

test("a signed out visibility change says so", async () => {
  visitor = null;

  expect(await setGameVisibility(null, form({ shortname: "BA", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.signedOut,
  });
});

test("a game an editableGame check finds nothing for is refused - a stranger, or an owner who lost their grant", async () => {
  gameLookup = null;

  expect(await setGameVisibility(null, form({ shortname: "BA", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notAllowed,
  });
});

test("a write the admin client refuses is reported as not saved", async () => {
  gameAdminResult = { error: new Error("db down") };

  expect(await setGameVisibility(null, form({ shortname: "BA", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSaved,
  });
});

test("no secret key on this deployment is reported as not saved", async () => {
  adminAvailable = false;

  expect(await setGameVisibility(null, form({ shortname: "BA", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSaved,
  });
});

test("a visibility form with no shortname never reaches the write", async () => {
  expect(await setGameVisibility(null, form({ hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSent,
  });
});

// #374: the moderation queue's unhide buttons and a game's own "Hide this
// game" shortcut make their own row or page disappear in the same response
// that would have shown their message, so a form that opts in with
// `onSuccess` is sent on to a page that still exists, with the message
// riding along as the `visibility` search param, rather than state on the
// component the write just took away. Every other form leaves `onSuccess`
// unset and keeps answering in place, proved by the tests above.

test("an unhide from the moderation queue redirects there with the message", async () => {
  const attempt = setGameVisibility(null, form({ shortname: "BA", hidden: "false", onSuccess: "moderation" }));

  await expect(attempt).rejects.toMatchObject({
    digest: "NEXT_REDIRECT;replace;/moderation/games?visibility=game-shown;307;",
  });
});

test("hiding a game from its own page redirects to its edit page with the message", async () => {
  const attempt = setGameVisibility(null, form({ shortname: "BA", hidden: "true", onSuccess: "edit" }));

  await expect(attempt).rejects.toMatchObject({
    digest: "NEXT_REDIRECT;replace;/games/BA/edit?visibility=game-hidden;307;",
  });
});

test("a refused unhide with onSuccess set still tells the form in place, not a redirect", async () => {
  visitor = null;

  expect(
    await setGameVisibility(null, form({ shortname: "BA", hidden: "false", onSuccess: "moderation" })),
  ).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.signedOut,
  });
});

test("hiding a release tells the form", async () => {
  const state = await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "true" }));

  expect(state).toEqual({ ok: true, message: "Release hidden." });
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual(["/games/BA", "/moderation/games"]);
});

test("showing a release again tells the form", async () => {
  expect(await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "false" }))).toEqual({
    ok: true,
    message: "Release shown again.",
  });
});

test("a signed out release visibility change says so", async () => {
  visitor = null;

  expect(await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.signedOut,
  });
});

test("a release an editableGame check finds nothing for is refused", async () => {
  gameLookup = null;

  expect(await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notAllowed,
  });
});

test("a release write the admin client refuses is reported as not saved", async () => {
  versionAdminResult = { error: new Error("db down") };

  expect(await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSaved,
  });
});

test("no secret key on this deployment is reported as not saved for a release too", async () => {
  adminAvailable = false;

  expect(await setVersionVisibility(null, form({ shortname: "BA", version: "1.9.0", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSaved,
  });
});

test("a release visibility form missing the version never reaches the write", async () => {
  expect(await setVersionVisibility(null, form({ shortname: "BA", hidden: "true" }))).toEqual({
    ok: false,
    message: VISIBILITY_MESSAGES.notSent,
  });
});

test("unhiding a release from the moderation queue redirects there with the message (#374)", async () => {
  const attempt = setVersionVisibility(
    null,
    form({ shortname: "BA", version: "1.9.0", hidden: "false", onSuccess: "moderation" }),
  );

  await expect(attempt).rejects.toMatchObject({
    digest: "NEXT_REDIRECT;replace;/moderation/games?visibility=release-shown;307;",
  });
});
