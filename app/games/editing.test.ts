import { beforeEach, expect, mock, test } from "bun:test";

// The web side of who may change a game (#350): the edit page and the logo and
// banner upload. The owner or a moderator gets through, and a signed in
// account that is neither gets a 404 and writes nothing. The database side is
// `supabase/tests/game_ownership.test.sql`, and the question both ask is
// proved on its own in `lib/games/editor.test.ts`.

const OWNER = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";
const STRANGER = "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb";
const MODERATOR = "cccccccc-cccc-cccc-cccc-cccccccccccc";

const GAME = {
  id: "game-ba",
  shortname: "BA",
  display_name: null,
  description: null,
  links: [],
  owner_user_id: OWNER,
  hidden_at: null,
  logo_path: null,
  banner_path: null,
  game_faction: [],
  game_version: [],
};

/** 2x2, 8 bit RGB, the same frozen encoder output `lib/assets/imageHeader.test.ts` uses. */
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC",
  "base64",
);

let visitor = STRANGER;

/** The visitor's own client. `game` rows are filtered by the `eq` calls, the
 *  way row level security and PostgREST would. The other tables the edit page
 *  reads answer with nothing much. */
function visitorClient(userId: string) {
  return {
    auth: { getUser: async () => ({ data: { user: { id: userId } }, error: null }) },
    rpc: async () => ({ data: userId === MODERATOR, error: null }),
    from: (table: string) => {
      const filters: [string, unknown][] = [];
      const answer = () => {
        if (table === "game_browse") return { faction_count: 0, unit_count: 0, item_count: 0 };
        if (table !== "game") return null;
        return filters.every(([column, value]) => GAME[column as keyof typeof GAME] === value)
          ? GAME
          : null;
      };
      const query = {
        select: () => query,
        order: () => query,
        limit: () => query,
        eq: (column: string, value: unknown) => {
          filters.push([column, value]);
          return query;
        },
        maybeSingle: async () => ({ data: answer(), error: null }),
        then: (resolve: (value: unknown) => void) => resolve({ data: [], error: null }),
      };
      return query;
    },
  };
}

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => visitorClient(visitor),
}));

const upload = mock(async () => ({ data: { path: "x" }, error: null }));
const update = mock(() => ({ eq: async () => ({ error: null }) }));

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({
    storage: { from: () => ({ upload }) },
    from: () => ({ update }),
  }),
}));

const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: () => {} }));

const { uploadGameImage } = await import("./actions");
const EditGame = (await import("./[shortname]/edit/page")).default;

function banner(): FormData {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "banner");
  form.set("image", new File([PNG], "banner.png", { type: "image/png" }));
  return form;
}

function editPage() {
  return EditGame({ params: Promise.resolve({ shortname: "BA" }) });
}

beforeEach(() => {
  upload.mockClear();
  update.mockClear();
});

test("a signed in account that is neither owner nor moderator gets 404 on the edit page", async () => {
  visitor = STRANGER;

  const error = await editPage().then(
    () => null,
    (thrown: { digest?: string }) => thrown,
  );

  expect(error?.digest).toContain("404");
});

test("a moderator who does not own the game gets the edit page", async () => {
  visitor = MODERATOR;

  expect(await editPage()).toBeTruthy();
});

test("the owner still gets the edit page", async () => {
  visitor = OWNER;

  expect(await editPage()).toBeTruthy();
});

test("a stranger's banner upload writes nothing to the bucket or the row", async () => {
  visitor = STRANGER;

  await uploadGameImage(banner());

  expect(upload).not.toHaveBeenCalled();
  expect(update).not.toHaveBeenCalled();
});

test("a moderator's banner upload lands in the bucket and marks the row staged there", async () => {
  visitor = MODERATOR;

  await uploadGameImage(banner());

  expect(upload).toHaveBeenCalledTimes(1);
  expect(update).toHaveBeenCalledWith(
    expect.objectContaining({ banner_path: "games/BA/banner.png", banner_staged_tier: "bucket" }),
  );
});
