import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { fetchSourceConflicts, recordSourceConflict, sourceConflict } from "./sourceConflict";

const USER = "11111111-1111-1111-1111-111111111111";
const STRANGER = "22222222-2222-2222-2222-222222222222";

function held(overrides: Partial<Parameters<typeof sourceConflict>[0]> = {}) {
  return {
    id: "held",
    source_hash: "raw-old",
    source_archive: "byar_1.1.sdz",
    moderation: "approved",
    ...overrides,
  };
}

function declared(overrides: Partial<Parameters<typeof sourceConflict>[1]> = {}) {
  return { sourceHash: "raw-new", sourceArchive: "byar_1.2.sdz", ...overrides };
}

/** The ordinary case, and by far the commonest one. A newer archive holds
 * different bytes because it is a different archive. */
test("a newer archive holding different bytes is a version rollover and not a conflict", () => {
  expect(sourceConflict(held(), declared(), USER)).toBeNull();
});

test("the same archive holding the same bytes is not a conflict either", () => {
  expect(
    sourceConflict(held({ source_archive: "byar_1.2.sdz", source_hash: "raw-new" }), declared(), USER),
  ).toBeNull();
});

/**
 * The whole of the issue. Extraction is deterministic, so one archive has one
 * answer, and two answers means one of the clients is not doing what it says.
 */
test("the same archive holding different bytes is the conflict", () => {
  expect(sourceConflict(held({ source_archive: "byar_1.2.sdz" }), declared(), STRANGER)).toEqual({
    assetId: "held",
    sourceArchive: "byar_1.2.sdz",
    heldSourceHash: "raw-old",
    reportedSourceHash: "raw-new",
    reportedBy: STRANGER,
  });
});

/** Compared on the raw archive bytes and never on the encoded ones, which
 * differ legitimately between Coilbox releases and libwebp builds. A rule that
 * read the encoded hash would flag the whole corpus after any encoder upgrade,
 * and the signal would be ignored inside a week. */
test("a pending row is flagged the same as an approved one", () => {
  expect(
    sourceConflict(held({ source_archive: "byar_1.2.sdz", moderation: "pending" }), declared(), USER),
  ).not.toBeNull();
});

/** Out of the queue, so a mark on it is a mark nobody sees, and a safety
 * rejection is a row #115 asks nothing in the hub to add to. */
test("a rejected row is left alone", () => {
  expect(
    sourceConflict(
      held({ source_archive: "byar_1.2.sdz", moderation: "rejected" }),
      declared(),
      USER,
    ),
  ).toBeNull();
});

interface Write {
  table: string;
  row: Record<string, unknown>;
  options: Record<string, unknown>;
}

function fakeSupabase(writes: Write[], rows: { asset_id: string }[] = []): SupabaseClient {
  const from = (table: string) => ({
    upsert: (row: Record<string, unknown>, options: Record<string, unknown>) => {
      writes.push({ table, row, options });
      return Promise.resolve({ error: null });
    },
    select: () => ({
      in: (_column: string, ids: string[]) =>
        Promise.resolve({ data: rows.filter((row) => ids.includes(row.asset_id)), error: null }),
    }),
  });

  return { from } as unknown as SupabaseClient;
}

/** One row per distinct set of reported bytes, so a client looping on the same
 * refused upload leaves one record rather than as many as it can send. */
test("a disagreement is written once per set of reported bytes", async () => {
  const writes: Write[] = [];
  const conflict = sourceConflict(held({ source_archive: "byar_1.2.sdz" }), declared(), STRANGER);
  if (!conflict) throw new Error("expected a conflict");

  expect(await recordSourceConflict(fakeSupabase(writes), conflict)).toBe(true);

  expect(writes).toEqual([
    {
      table: "asset_source_conflict",
      row: {
        asset_id: "held",
        source_archive: "byar_1.2.sdz",
        held_source_hash: "raw-old",
        reported_source_hash: "raw-new",
        reported_by: STRANGER,
      },
      options: { onConflict: "asset_id,reported_source_hash", ignoreDuplicates: true },
    },
  ]);
});

test("the grid asks about the page it is showing and gets back only what is flagged", async () => {
  const flagged = await fetchSourceConflicts(fakeSupabase([], [{ asset_id: "b" }]), ["a", "b"]);

  expect([...flagged]).toEqual(["b"]);
});

/** An empty page asks nothing, since a filter on no ids is a request that can
 * only answer nothing. */
test("an empty queue costs no query", async () => {
  const writes: Write[] = [];
  expect([...(await fetchSourceConflicts(fakeSupabase(writes), []))]).toEqual([]);
  expect(writes).toEqual([]);
});
