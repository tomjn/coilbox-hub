import { beforeEach, expect, mock, test } from "bun:test";

// What `reportMap` tells the form for each answer the database can give (#294).
// Who may report is the insert policy on public.report, proved in
// supabase/tests/map_report.test.sql, so what is checked here is the
// validation before the write and the translation of its result into words.

const MAP_ID = "0f8fad5b-0294-4000-8000-000000000001";
const USER_ID = "dddddddd-dddd-dddd-dddd-dddddddddddd";

let visitor: string | null = null;
let insertResult: { error: unknown } = { error: null };
const inserted = mock<(row: Record<string, unknown>) => void>(() => {});

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: { getUser: async () => ({ data: { user: visitor ? { id: visitor } : null }, error: null }) },
    from: (table: string) => {
      if (table !== "report") throw new Error(`unexpected table ${table}`);
      return {
        insert: async (row: Record<string, unknown>) => {
          inserted(row);
          return insertResult;
        },
      };
    },
  }),
}));

const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: mock(() => {}), updateTag: mock(() => {}) }));

const { reportMap } = await import("./actions");
const { MAP_REPORT_MESSAGES } = await import("@/lib/maps/report");

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) data.set(key, value);
  return data;
}

beforeEach(() => {
  visitor = null;
  insertResult = { error: null };
  inserted.mockClear();
});

test("a signed out visitor's report is stored with no reporter and says so", async () => {
  const state = await reportMap(
    null,
    form({ map_id: MAP_ID, kind: "crashes_engine", reason: "  Closes on load  " }),
  );

  expect(state).toEqual({ ok: true, message: MAP_REPORT_MESSAGES.sent });
  expect(inserted.mock.calls[0][0]).toEqual({
    map_id: MAP_ID,
    kind: "crashes_engine",
    reason: "Closes on load",
    reporter_id: null,
  });
});

test("a signed in reporter is recorded", async () => {
  visitor = USER_ID;

  await reportMap(null, form({ map_id: MAP_ID, kind: "other", reason: "Odd" }));

  expect(inserted.mock.calls[0][0].reporter_id).toBe(USER_ID);
});

test("a reason longer than the column allows is cut rather than refused", async () => {
  await reportMap(null, form({ map_id: MAP_ID, kind: "other", reason: "x".repeat(1500) }));

  expect((inserted.mock.calls[0][0].reason as string).length).toBe(1000);
});

test("a kind outside the list is refused before any write", async () => {
  const state = await reportMap(null, form({ map_id: MAP_ID, kind: "smells_funny", reason: "Odd" }));

  expect(state).toEqual({ ok: false, message: MAP_REPORT_MESSAGES.noKind });
  expect(inserted).not.toHaveBeenCalled();
});

test("a blank reason is refused before any write", async () => {
  const state = await reportMap(null, form({ map_id: MAP_ID, kind: "other", reason: "   " }));

  expect(state).toEqual({ ok: false, message: MAP_REPORT_MESSAGES.noReason });
  expect(inserted).not.toHaveBeenCalled();
});

test("a form with no map is refused before any write", async () => {
  const state = await reportMap(null, form({ kind: "other", reason: "Odd" }));

  expect(state).toEqual({ ok: false, message: MAP_REPORT_MESSAGES.notSent });
  expect(inserted).not.toHaveBeenCalled();
});

test("a failed write is reported as one the reader can retry", async () => {
  insertResult = { error: { message: "boom" } };

  const state = await reportMap(null, form({ map_id: MAP_ID, kind: "other", reason: "Odd" }));

  expect(state).toEqual({ ok: false, message: MAP_REPORT_MESSAGES.notSaved });
});
