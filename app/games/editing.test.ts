import { beforeEach, expect, mock, test } from "bun:test";

// The web side of who may change a game (#350): the edit page, the logo and
// banner upload, and removing either (#360). The owner or a moderator gets through, and a signed in
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

let visitor: string | null = STRANGER;

/** The visitor's own client. `game` rows are filtered by the `eq` calls, the
 *  way row level security and PostgREST would. The other tables the edit page
 *  reads answer with nothing much. A null visitor is signed out. */
function visitorClient(userId: string | null) {
  return {
    auth: { getUser: async () => ({ data: { user: userId ? { id: userId } : null }, error: null }) },
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

const upload = mock(async (): Promise<{ data: unknown; error: Error | null }> => ({ data: { path: "x" }, error: null }));
const rowWrite = mock(async (): Promise<{ error: Error | null }> => ({ error: null }));
const update = mock(() => ({ eq: rowWrite }));

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({
    storage: { from: () => ({ upload }) },
    from: () => ({ update }),
  }),
}));

const revalidated = mock<(path: string) => void>(() => {});
const updatedTags = mock<(tag: string) => void>(() => {});
const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: revalidated, updateTag: updatedTags }));

const { removeGameImage, uploadGameImage } = await import("./actions");
const { REMOVE_MESSAGES, UPLOAD_MESSAGES } = await import("@/lib/games/imageUpload");
const EditGame = (await import("./[shortname]/edit/page")).default;

function banner(image: File = new File([PNG], "banner.png", { type: "image/png" })): FormData {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "banner");
  form.set("image", image);
  return form;
}

function uploadBanner(form: FormData = banner()) {
  return uploadGameImage(null, form);
}

function editPage() {
  return EditGame({ params: Promise.resolve({ shortname: "BA" }), searchParams: Promise.resolve({}) });
}

beforeEach(() => {
  upload.mockClear();
  rowWrite.mockClear();
  update.mockClear();
  revalidated.mockClear();
  updatedTags.mockClear();
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

test("a stranger's banner upload writes nothing to the bucket or the row, and says why", async () => {
  visitor = STRANGER;

  expect(await uploadBanner()).toEqual({ ok: false, message: UPLOAD_MESSAGES.notAllowed });

  expect(upload).not.toHaveBeenCalled();
  expect(update).not.toHaveBeenCalled();
});

test("a moderator's banner upload lands in the bucket, marks the row staged there, and confirms it", async () => {
  visitor = MODERATOR;

  expect(await uploadBanner()).toEqual({ ok: true, message: "Banner uploaded." });

  expect(upload).toHaveBeenCalledTimes(1);
  expect(update).toHaveBeenCalledWith(
    expect.objectContaining({ banner_path: "games/BA/banner.png", banner_staged_tier: "bucket" }),
  );
  expect(updatedTags).toHaveBeenCalledWith("games");
});

test("an upload with no file asks for one", async () => {
  visitor = OWNER;
  const form = banner();
  form.delete("image");

  expect(await uploadBanner(form)).toEqual({ ok: false, message: UPLOAD_MESSAGES.noFile });
  expect(await uploadBanner(banner(new File([], "empty.png")))).toEqual({ ok: false, message: UPLOAD_MESSAGES.noFile });
  expect(upload).not.toHaveBeenCalled();
});

test("a file over 512 KB is refused with its size, before anything is stored", async () => {
  visitor = OWNER;

  const state = await uploadBanner(banner(new File([new Uint8Array(720_583)], "big.png")));

  expect(state).toEqual({
    ok: false,
    message: "This file is 704 KB. The largest picture you can upload is 512 KB. Make it smaller and try again.",
  });
  expect(upload).not.toHaveBeenCalled();
});

test("a JPEG is refused as not a PNG or WebP", async () => {
  visitor = OWNER;
  // The first bytes of every JPEG, then filler.
  const jpeg = new Uint8Array(64);
  jpeg.set([0xff, 0xd8, 0xff, 0xe0]);

  expect(await uploadBanner(banner(new File([jpeg], "photo.jpg")))).toEqual({
    ok: false,
    message: UPLOAD_MESSAGES.wrongType,
  });
  expect(upload).not.toHaveBeenCalled();
});

test("a signed out upload asks the visitor to sign in", async () => {
  visitor = null;

  expect(await uploadBanner()).toEqual({ ok: false, message: UPLOAD_MESSAGES.signedOut });
  expect(upload).not.toHaveBeenCalled();
});

test("a bucket that refuses the upload is reported and leaves the row alone", async () => {
  visitor = OWNER;
  upload.mockImplementationOnce(async () => ({ data: null, error: new Error("storage down") }));

  expect(await uploadBanner()).toEqual({ ok: false, message: UPLOAD_MESSAGES.notSaved });
  expect(update).not.toHaveBeenCalled();
});

test("a row that will not take the new path is reported", async () => {
  visitor = OWNER;
  rowWrite.mockImplementationOnce(async () => ({ error: new Error("row refused") }));

  expect(await uploadBanner()).toEqual({ ok: false, message: UPLOAD_MESSAGES.notSaved });
});

function removal(kind: string): FormData {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", kind);
  return form;
}

test("a stranger cannot remove a logo, and nothing is written or revalidated", async () => {
  visitor = STRANGER;

  expect(await removeGameImage(null, removal("logo"))).toEqual({ ok: false, message: REMOVE_MESSAGES.notAllowed });

  expect(update).not.toHaveBeenCalled();
  expect(revalidated).not.toHaveBeenCalled();
});

test("a signed out removal asks the visitor to sign in", async () => {
  visitor = null;

  expect(await removeGameImage(null, removal("logo"))).toEqual({ ok: false, message: REMOVE_MESSAGES.signedOut });
  expect(update).not.toHaveBeenCalled();
});

test("a moderator who does not own the game removes its banner: all three banner columns cleared, and the pages told", async () => {
  visitor = MODERATOR;

  expect(await removeGameImage(null, removal("banner"))).toEqual({ ok: true, message: "Banner removed." });

  expect(update).toHaveBeenCalledWith({ banner_path: null, banner_hash: null, banner_staged_tier: null });
  expect(rowWrite).toHaveBeenCalledWith("id", GAME.id);
  expect(upload).not.toHaveBeenCalled();
  expect(updatedTags).toHaveBeenCalledWith("games");
  expect(revalidated.mock.calls.map(([path]) => path).sort()).toEqual(["/games", "/games/BA"]);
});

test("the owner removes the logo, and only the logo columns are cleared", async () => {
  visitor = OWNER;

  expect(await removeGameImage(null, removal("logo"))).toEqual({ ok: true, message: "Logo removed." });
  expect(update).toHaveBeenCalledWith({ logo_path: null, logo_hash: null, logo_staged_tier: null });
});

test("a removal naming neither a logo nor a banner writes nothing", async () => {
  visitor = OWNER;

  expect(await removeGameImage(null, removal("faction_logo"))).toEqual({ ok: false, message: REMOVE_MESSAGES.notSent });
  expect(update).not.toHaveBeenCalled();
});

test("a row that will not clear is reported, and the pages are not told anything changed", async () => {
  visitor = OWNER;
  rowWrite.mockImplementationOnce(async () => ({ error: new Error("row refused") }));

  expect(await removeGameImage(null, removal("logo"))).toEqual({ ok: false, message: REMOVE_MESSAGES.notSaved });
  expect(revalidated).not.toHaveBeenCalled();
});
