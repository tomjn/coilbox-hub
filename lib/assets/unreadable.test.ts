import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  type BlobAssetRow,
  type BlobRead,
  clearBytesMissing,
  findUnreadable,
  markBytesMissing,
  readability,
} from "./unreadable";

const LOST_AT = "2026-09-14T12:00:00.000Z";

function row(id: string, over: Partial<BlobAssetRow> = {}): BlobAssetRow {
  return { id, path: `units/bar/buildpic/${id}-Hn4vQ2rT.webp`, bytes: 4096, bytes_missing_at: null, ...over };
}

test("a suspended store's 403 and a deleted object's 404 both mean the bytes are lost", () => {
  expect(readability({ status: 403, byteLength: 22 }, 4096)).toBe("lost");
  expect(readability({ status: 404, byteLength: 0 }, 4096)).toBe("lost");
});

test("a 200 is readable only when it carries the bytes the row says", () => {
  expect(readability({ status: 200, byteLength: 4096 }, 4096)).toBe("readable");
  expect(readability({ status: 200, byteLength: 22 }, 4096)).toBe("lost");
});

test("a server error or no answer at all is not evidence either way", () => {
  expect(readability({ status: 503, byteLength: 0 }, 4096)).toBe("unknown");
  expect(readability({ status: null, byteLength: 0 }, 4096)).toBe("unknown");
});

test("rows are sorted by what their answer means for the mark", async () => {
  const rows = [
    row("a"),
    row("b"),
    row("c", { bytes_missing_at: LOST_AT }),
    row("d", { bytes_missing_at: LOST_AT }),
    row("e", { bytes_missing_at: LOST_AT }),
  ];
  const answers: Record<string, BlobRead> = {
    a: { status: 403, byteLength: 22 },
    b: { status: 200, byteLength: 4096 },
    c: { status: 200, byteLength: 4096 },
    d: { status: 403, byteLength: 22 },
    e: { status: null, byteLength: 0 },
  };
  const said: string[] = [];

  const findings = await findUnreadable(
    rows,
    async (path) => answers[path.split("/").pop()!.split("-")[0]],
    (message) => said.push(message),
  );

  expect(findings.lost.map((r) => r.id)).toEqual(["a"]);
  expect(findings.back.map((r) => r.id)).toEqual(["c"]);
  expect(findings.stillLost).toBe(1);
  expect(findings.readable).toBe(1);
  expect(findings.unknown.map((r) => r.id)).toEqual(["e"]);
  expect(Object.fromEntries(findings.statuses)).toEqual({ "403": 2, "200": 2, "no answer": 1 });
  expect(said.map((line) => line.split(" ")[0])).toEqual(["lost", "back", "unknown"]);
});

interface Update {
  values: Record<string, unknown>;
  filters: string[];
}

/** Records each update and the filters on it, and answers with every id it was
 *  handed as if all of them matched. */
function recordingUpdates(updates: Update[]): SupabaseClient {
  return {
    from: () => ({
      update: (values: Record<string, unknown>) => {
        const update: Update = { values, filters: [] };
        updates.push(update);
        let ids: string[] = [];
        const builder = {
          in: (column: string, values: string[]) => {
            ids = values;
            update.filters.push(`in:${column}`);
            return builder;
          },
          eq: (column: string, value: string) => {
            update.filters.push(`eq:${column}:${value}`);
            return builder;
          },
          is: (column: string, value: null) => {
            update.filters.push(`is:${column}:${value}`);
            return builder;
          },
          not: (column: string, op: string, value: null) => {
            update.filters.push(`not:${column}:${op}:${value}`);
            return builder;
          },
          select: () => Promise.resolve({ data: ids.map((id) => ({ id })), error: null }),
        };
        return builder;
      },
    }),
  } as unknown as SupabaseClient;
}

test("marking touches only rows still in Blob and not marked yet, so a re-uploaded row keeps its bytes", async () => {
  const updates: Update[] = [];
  const ids = Array.from({ length: 120 }, (_, n) => `id-${n}`);

  expect(await markBytesMissing(recordingUpdates(updates), ids, new Date(LOST_AT))).toBe(120);

  expect(updates.length).toBeGreaterThan(1);
  for (const update of updates) {
    expect(update.values).toEqual({ bytes_missing_at: LOST_AT });
    expect(update.filters).toEqual(["in:id", "eq:tier:blob", "is:bytes_missing_at:null"]);
  }
});

test("clearing touches only rows still in Blob that are marked", async () => {
  const updates: Update[] = [];

  expect(await clearBytesMissing(recordingUpdates(updates), ["c"])).toBe(1);

  expect(updates).toEqual([
    { values: { bytes_missing_at: null }, filters: ["in:id", "eq:tier:blob", "not:bytes_missing_at:is:null"] },
  ]);
});

test("nothing to mark or clear makes no request", async () => {
  const updates: Update[] = [];

  expect(await markBytesMissing(recordingUpdates(updates), [])).toBe(0);
  expect(await clearBytesMissing(recordingUpdates(updates), [])).toBe(0);
  expect(updates).toEqual([]);
});
