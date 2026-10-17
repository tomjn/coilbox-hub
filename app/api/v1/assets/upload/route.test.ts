import { beforeEach, expect, mock, test } from "bun:test";
import { SUBJECT_UPLOADS_PER_HOUR } from "@/lib/assets/upload";

// Replacing somebody else's picture through the route (#138). Bearer
// authentication and the secret key client are replaced. Everything between
// them is the real route and the real checks, so what is under test is the
// whole decision: who may replace a picture they did not upload, what the row
// says afterwards, and that every other refusal still applies to them.

const HOLDER = "b2222222-2222-2222-2222-222222222222";
const UPLOADER = "a1111111-1111-1111-1111-111111111111";

interface Row {
  id: string;
  source_hash: string;
  source_archive: string;
  uploaded_by: string | null;
  moderation: string;
  bytes: number;
  bytes_missing_at: string | null;
}

let held: string[] = [];
let asked: string[] = [];
let existing: Row | null = null;
let recent = 0;
let updates: Record<string, unknown>[] = [];
let inserts: Record<string, unknown>[] = [];
let stored: string[] = [];

function row(overrides: Partial<Row> = {}): Row {
  return {
    id: "held",
    source_hash: "raw-old",
    source_archive: "byar_1.1.sdz",
    uploaded_by: UPLOADER,
    moderation: "approved",
    bytes: 40,
    bytes_missing_at: null,
    ...overrides,
  };
}

/** The uploader's own client, which is the only thing has_capability answers
 * for. */
const uploaderClient = {
  rpc: (_name: string, args: { capability: string }) => {
    asked.push(args.capability);
    return Promise.resolve({ data: held.includes(args.capability), error: null });
  },
};

/** The secret key client, answering the checks and recording the writes. */
function adminClient() {
  const from = (table: string) => {
    const filters: string[] = [];
    let write: "update" | "insert" | null = null;
    const answer = () => {
      if (table !== "asset") return { data: null, error: null };
      if (filters.some((filter) => filter.startsWith("or:"))) return { data: existing, error: null };
      if (write === "insert") return { data: { id: "new-row" }, error: null };
      if (write === "update") return { error: null };
      if (filters.includes("eq:uploaded_by")) return { count: recent, error: null };
      return { count: 0, error: null };
    };
    const builder = {
      select: () => builder,
      eq: (column: string) => {
        filters.push(`eq:${column}`);
        return builder;
      },
      gte: () => builder,
      not: () => builder,
      like: () => builder,
      or: (filter: string) => {
        filters.push(`or:${filter}`);
        return builder;
      },
      update: (values: Record<string, unknown>) => {
        write = "update";
        updates.push(values);
        return builder;
      },
      insert: (values: Record<string, unknown>) => {
        write = "insert";
        if (table === "asset") inserts.push(values);
        return builder;
      },
      upsert: () => builder,
      maybeSingle: () => Promise.resolve(answer()),
      single: () => Promise.resolve(answer()),
      then: (onOk: (value: unknown) => unknown, onErr?: (reason: unknown) => unknown) =>
        Promise.resolve(answer()).then(onOk, onErr),
    };
    return builder;
  };

  return {
    from,
    rpc: () => Promise.resolve({ data: 0, error: null }),
    storage: {
      from: () => ({
        upload: (path: string) => {
          stored.push(path);
          return Promise.resolve({ error: null });
        },
      }),
    },
  };
}

const realBearer = await import("@/lib/supabase/bearer");
mock.module("@/lib/supabase/bearer", () => ({
  ...realBearer,
  authenticateBearer: async () => ({ ok: true, user: { id: HOLDER }, supabase: uploaderClient }),
}));

const realAdmin = await import("@/lib/supabase/admin");
mock.module("@/lib/supabase/admin", () => ({
  ...realAdmin,
  createAdminClient: () => adminClient(),
}));

const { POST } = await import("./route");

/** A lossless WebP header at 128px, which is all a buildpic's checks read. */
function buildpic(): Uint8Array<ArrayBuffer> {
  const bytes = new Uint8Array(40);
  const view = new DataView(bytes.buffer);
  bytes.set(new TextEncoder().encode("RIFF"), 0);
  bytes.set(new TextEncoder().encode("WEBPVP8L"), 8);
  view.setUint32(16, 20, true);
  bytes[20] = 0x2f;
  view.setUint32(21, 127 | (127 << 14), true);
  return bytes;
}

function upload(): Promise<Response> {
  const bytes = buildpic();
  const form = new FormData();
  form.set(
    "asset",
    JSON.stringify({
      keyed_on: "unit",
      game: "BYAR",
      unit_name: "armsolar",
      variant: "buildpic",
      source_hash: "raw-new",
      encode_profile: "webp-lossless-256",
      origin: "extracted",
      mime: "image/webp",
      bytes: bytes.byteLength,
      source_archive: "byar_1.2.sdz",
    }),
  );
  form.set("file", new Blob([bytes], { type: "image/webp" }));

  return POST(
    new Request("http://hub.test/api/v1/assets/upload", {
      method: "POST",
      headers: { Authorization: "Bearer token" },
      body: form,
    }),
  );
}

beforeEach(() => {
  held = [];
  asked = [];
  existing = row();
  recent = 0;
  updates = [];
  inserts = [];
  stored = [];
});

test("a holder replaces another account's picture, and it goes back to the queue as theirs", async () => {
  held = ["can_replace_any_asset"];

  const response = await upload();

  expect(response.status).toBe(200);
  expect((await response.json()).moderation).toBe("pending");
  expect(stored).toHaveLength(1);
  expect(updates).toHaveLength(1);
  expect(updates[0]).toMatchObject({
    uploaded_by: HOLDER,
    moderation: "pending",
    approval_source: null,
    source_hash: "raw-new",
  });
});

test("a holder replaces a picture whose uploader closed their account", async () => {
  held = ["can_replace_any_asset"];
  existing = row({ uploaded_by: null });

  const response = await upload();

  expect(response.status).toBe(200);
  expect(updates[0]).toMatchObject({ uploaded_by: HOLDER, moderation: "pending" });
});

test("an account without the capability cannot, and nothing is stored or written", async () => {
  const response = await upload();

  expect(response.status).toBe(409);
  expect(asked).toContain("can_replace_any_asset");
  expect(stored).toEqual([]);
  expect(updates).toEqual([]);
  expect(inserts).toEqual([]);
});

test("a moderator without the capability cannot either", async () => {
  held = ["can_moderate", "can_publish_unreviewed"];

  const response = await upload();

  expect(response.status).toBe(409);
  expect(stored).toEqual([]);
  expect(updates).toEqual([]);
});

test("a closed account's picture is refused to an account without the capability", async () => {
  existing = row({ uploaded_by: null });

  const response = await upload();

  expect(response.status).toBe(409);
  expect(updates).toEqual([]);
});

test("a holder over the hourly limit is turned away before anything is stored", async () => {
  held = ["can_replace_any_asset"];
  recent = SUBJECT_UPLOADS_PER_HOUR;

  const response = await upload();

  expect(response.status).toBe(429);
  expect(stored).toEqual([]);
  expect(updates).toEqual([]);
});

test("a holder cannot put a rejected picture back in the queue", async () => {
  held = ["can_replace_any_asset"];
  existing = row({ moderation: "rejected" });

  const response = await upload();

  expect(response.status).toBe(409);
  expect((await response.json()).error).toContain("rejected");
  expect(updates).toEqual([]);
});

test("a holder who also skips the queue publishes the replacement, as a bypass", async () => {
  held = ["can_replace_any_asset", "can_publish_unreviewed"];

  const response = await upload();

  expect(response.status).toBe(200);
  expect((await response.json()).moderation).toBe("approved");
  expect(updates[0]).toMatchObject({
    uploaded_by: HOLDER,
    moderation: "approved",
    approval_source: "bypass",
  });
});

test("an account replacing its own picture is never asked about the capability", async () => {
  existing = row({ uploaded_by: HOLDER });

  const response = await upload();

  expect(response.status).toBe(200);
  expect(asked).not.toContain("can_replace_any_asset");
  expect(updates[0]).toMatchObject({ uploaded_by: HOLDER, moderation: "pending" });
});
