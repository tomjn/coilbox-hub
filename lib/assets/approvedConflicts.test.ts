import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  acknowledgeSourceConflict,
  conflictId,
  fetchApprovedSourceConflicts,
} from "./approvedConflicts";
import { QUEUE_PAGE_SIZE } from "./queue";

interface Call {
  fn: string;
  args: Record<string, unknown>;
}

function fakeSupabase(calls: Call[], result: { data: unknown; error: { message: string } | null }) {
  return {
    rpc: (fn: string, args: Record<string, unknown>) => {
      calls.push({ fn, args });
      return Promise.resolve(result);
    },
  } as unknown as SupabaseClient;
}

const ROW = {
  conflict_id: 7,
  asset_id: "0f8fad5b-0000-4000-8000-0000000000a1",
  game: "bar",
  unit_name: "armsolar",
  map_name: null,
  faction_key: null,
  variant: "buildpic",
  source_archive: "bar_1.2.sdz",
  held_source_hash: "src-one",
  reported_source_hash: "src-other",
  reported_by: "22222222-2222-2222-2222-222222222222",
  uploaded_by: "11111111-1111-1111-1111-111111111111",
  at: "2026-10-17T09:00:00Z",
  total: 3,
};

test("the list asks the database for a page the size of the moderation queue's", async () => {
  const calls: Call[] = [];
  await fetchApprovedSourceConflicts(fakeSupabase(calls, { data: [], error: null }));

  expect(calls).toEqual([
    { fn: "approved_source_conflicts", args: { p_limit: QUEUE_PAGE_SIZE } },
  ]);
});

test("a row becomes something the page can show, with the picture captioned", async () => {
  const result = await fetchApprovedSourceConflicts(
    fakeSupabase([], { data: [ROW], error: null }),
  );

  expect(result).toEqual({
    conflicts: [
      {
        conflictId: 7,
        assetId: "0f8fad5b-0000-4000-8000-0000000000a1",
        name: "armsolar",
        detail: "bar buildpic",
        sourceArchive: "bar_1.2.sdz",
        heldSourceHash: "src-one",
        reportedSourceHash: "src-other",
        reportedBy: "22222222-2222-2222-2222-222222222222",
        uploadedBy: "11111111-1111-1111-1111-111111111111",
        at: "2026-10-17T09:00:00Z",
      },
    ],
    total: 3,
    error: null,
  });
});

/** A list that failed to load must not read as a list with nothing in it. */
test("a failed read comes back as an error and not as an empty list", async () => {
  const result = await fetchApprovedSourceConflicts(
    fakeSupabase([], { data: null, error: { message: "the list is for moderators" } }),
  );

  expect(result).toEqual({ conflicts: [], total: 0, error: "the list is for moderators" });
});

test("an empty list has a total of nothing", async () => {
  const result = await fetchApprovedSourceConflicts(fakeSupabase([], { data: [], error: null }));

  expect(result).toEqual({ conflicts: [], total: 0, error: null });
});

test("acknowledging passes the entry's id and reports whether anything was cleared", async () => {
  const calls: Call[] = [];

  expect(await acknowledgeSourceConflict(fakeSupabase(calls, { data: true, error: null }), 7)).toBe(
    true,
  );
  expect(calls).toEqual([
    { fn: "acknowledge_source_conflict", args: { p_conflict_id: 7 } },
  ]);
  expect(
    await acknowledgeSourceConflict(fakeSupabase([], { data: false, error: null }), 7),
  ).toBe(false);
  expect(
    await acknowledgeSourceConflict(
      fakeSupabase([], { data: null, error: { message: "nope" } }),
      7,
    ),
  ).toBe(false);
});

/** The id comes off a form field, so it has to look like an id before it is
 * sent anywhere. */
test("only a plain positive whole number is an entry id", () => {
  expect(conflictId("7")).toBe(7);
  expect(conflictId("0")).toBeNull();
  expect(conflictId("-1")).toBeNull();
  expect(conflictId("1.5")).toBeNull();
  expect(conflictId("7; drop table")).toBeNull();
  expect(conflictId("")).toBeNull();
  expect(conflictId("99999999999999999999")).toBeNull();
});
