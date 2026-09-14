import { beforeEach, expect, mock, test } from "bun:test";

// Who may send a game's art through the API (#350): the owner or a moderator,
// and nobody else. The route asks `editableGame` with the visitor's own client,
// replaced here by one that answers `is_moderator()` and filters the game row
// the way row level security would. The secret key client is replaced by one
// that records whether anything reached the bucket or the row.

const OWNER = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";
const STRANGER = "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb";
const MODERATOR = "cccccccc-cccc-cccc-cccc-cccccccccccc";

const GAME = { id: "game-ba", shortname: "BA", owner_user_id: OWNER };

/** 2x2, 8 bit RGB, the same frozen encoder output `lib/assets/imageHeader.test.ts` uses. */
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC",
  "base64",
);

let visitor = STRANGER;

function visitorClient(userId: string) {
  return {
    rpc: async () => ({ data: userId === MODERATOR, error: null }),
    from: () => {
      const filters: [string, unknown][] = [];
      const query = {
        select: () => query,
        eq: (column: string, value: unknown) => {
          filters.push([column, value]);
          return query;
        },
        maybeSingle: async () => ({
          data: filters.every(([column, value]) => GAME[column as keyof typeof GAME] === value)
            ? { id: GAME.id }
            : null,
          error: null,
        }),
      };
      return query;
    },
  };
}

const real = await import("@/lib/supabase/bearer");
mock.module("@/lib/supabase/bearer", () => ({
  ...real,
  authenticateBearer: async () => ({
    ok: true,
    user: { id: visitor },
    supabase: visitorClient(visitor),
  }),
}));

const upload = mock(async () => ({ data: { path: "x" }, error: null }));
const update = mock(() => ({ eq: async () => ({ error: null }) }));

mock.module("@/lib/supabase/admin", () => ({
  createAdminClient: () => ({
    storage: { from: () => ({ upload }) },
    from: () => ({
      select: () => ({ eq: () => ({ maybeSingle: async () => ({ data: null, error: null }) }) }),
      update,
    }),
  }),
}));

const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidateTag: () => {} }));

const { POST } = await import("./route");

function send() {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "logo");
  form.set("file", new Blob([PNG], { type: "image/png" }), "logo.png");
  return POST(
    new Request("http://hub.test/api/v1/games/branding", {
      method: "POST",
      headers: { authorization: "Bearer token" },
      body: form,
    }),
  );
}

beforeEach(() => {
  upload.mockClear();
  update.mockClear();
});

test("a signed in account that is neither owner nor moderator is refused and writes nothing", async () => {
  visitor = STRANGER;

  const response = await send();

  expect(response.status).toBe(403);
  expect(upload).not.toHaveBeenCalled();
  expect(update).not.toHaveBeenCalled();
});

test("a moderator who does not own the game stores its logo in the bucket", async () => {
  visitor = MODERATOR;

  const response = await send();

  expect(response.status).toBe(200);
  expect((await response.json()).outcome).toBe("stored");
  expect(upload).toHaveBeenCalledTimes(1);
  expect(update).toHaveBeenCalledWith(
    expect.objectContaining({ logo_path: "games/BA/logo.png", logo_staged_tier: "bucket" }),
  );
});

test("the owner still stores their own game's logo", async () => {
  visitor = OWNER;

  const response = await send();

  expect(response.status).toBe(200);
  expect(upload).toHaveBeenCalledTimes(1);
});
