import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  BLOB_PUT_BUDGET,
  BLOB_WINDOW_DAYS,
  type BlobPutDay,
  blobWindowStart,
  fetchBlobPutDays,
  uploadsResume,
} from "./blobLedger";

const NOW = new Date("2026-09-14T12:00:00.000Z");

function client(rows: unknown[] | null): SupabaseClient {
  return {
    rpc: () =>
      Promise.resolve(
        rows === null ? { data: null, error: { message: "down" } } : { data: rows, error: null },
      ),
  } as unknown as SupabaseClient;
}

test("the window is 30 days back to the minute, not the start of the month", () => {
  expect(blobWindowStart(NOW)).toBe("2026-08-15T12:00:00.000Z");
});

test("every day the window touches is there, the quiet ones as zero", async () => {
  const days = await fetchBlobPutDays(
    client([
      { day: "2026-08-27", kind: "asset", puts: 1400 },
      { day: "2026-09-10", kind: "game_image", puts: 3 },
      { day: "2026-09-10", kind: "asset", puts: 560 },
      // Outside the window, which the function never returns, and ignored if it did.
      { day: "2026-08-01", kind: "asset", puts: 9 },
    ]),
    NOW,
  );

  expect(days).toHaveLength(BLOB_WINDOW_DAYS + 1);
  expect(days?.[0]).toEqual({ day: "2026-08-15", asset: 0, gameImage: 0 });
  expect(days?.at(-1)).toEqual({ day: "2026-09-14", asset: 0, gameImage: 0 });
  expect(days?.find((day) => day.day === "2026-08-27")).toEqual({
    day: "2026-08-27",
    asset: 1400,
    gameImage: 0,
  });
  expect(days?.find((day) => day.day === "2026-09-10")).toEqual({
    day: "2026-09-10",
    asset: 560,
    gameImage: 3,
  });
});

test("a failed read is no days, not thirty quiet ones", async () => {
  expect(await fetchBlobPutDays(client(null), NOW)).toBeNull();
});

const day = (date: string, asset: number): BlobPutDay => ({ day: date, asset, gameImage: 0 });

test("uploads under the budget resume on no particular day, because they never stopped", () => {
  expect(uploadsResume([day("2026-08-27", BLOB_PUT_BUDGET - 1)])).toBeNull();
});

test("uploads over the budget resume the day enough of the oldest puts age out", () => {
  const days = [day("2026-08-20", 100), day("2026-08-27", 1400), day("2026-09-10", 560)];

  // 2,060 in the window. The 20 August puts leaving takes it to 1,960, still
  // over. The 27 August ones leaving takes it to 560, which is under.
  expect(uploadsResume(days)).toBe("2026-09-26");
});
