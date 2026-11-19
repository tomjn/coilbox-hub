import { expect, test } from "bun:test";
import type { PromotionStore } from "./jobStore";
import {
  type OutstandingWithdrawal,
  fetchOutstandingWithdrawals,
  recordWithdrawn,
  withdrawalReport,
} from "./withdraw";

function owed(overrides: Partial<OutstandingWithdrawal> = {}): OutstandingWithdrawal {
  return {
    asset_id: "0f8fad5b-0000-4000-8000-00000000000a",
    path: "maps/minimap/enc-promoted.webp",
    at: "2026-08-20T09:00:00Z",
    ...overrides,
  };
}

/** The hub answering `answer`, or failing with its message. */
function fakeStore(answer: { data: unknown; error: { message: string } | null }): PromotionStore {
  const reply = async () => {
    if (answer.error) throw new Error(answer.error.message);
    return answer.data;
  };
  return { withdrawals: reply, recordWithdrawn: reply } as unknown as PromotionStore;
}

test("the queue is what the hub says is outstanding", async () => {
  const rows = await fetchOutstandingWithdrawals(fakeStore({ data: [owed()], error: null }));

  expect(rows).toEqual([owed()]);
});

/** A failed read is not an empty queue. Reading one as the other is how a
 * takedown gets quietly dropped by a database having a bad minute. */
test("a queue that cannot be read is an error and never an empty list", async () => {
  await expect(
    fetchOutstandingWithdrawals(fakeStore({ data: null, error: { message: "down" } })),
  ).rejects.toThrow("Could not read the takedown queue: down");
});

test("nothing to settle asks nothing, and what settles is what the database says settled", async () => {
  expect(await recordWithdrawn(fakeStore({ data: 2, error: null }), [])).toBe(0);
  expect(await recordWithdrawn(fakeStore({ data: 1, error: null }), ["a", "b"])).toBe(1);
});

test("an ordinary run says nothing about a queue that is empty", () => {
  expect(withdrawalReport([])).toEqual([]);
});

/**
 * The one thing the report has to get across, because the reader is a job's
 * output rather than the moderator who made the decision: a commit removing the
 * file is not the end of it.
 */
test("a run with something owed names the file and says a commit is not enough", () => {
  const lines = withdrawalReport([owed(), owed({ asset_id: "b", path: "units/bar/buildpic/x.webp" })]);
  const said = lines.join("\n");

  expect(lines[0]).toContain("2 picture(s)");
  expect(said).toContain("maps/minimap/enc-promoted.webp");
  expect(said).toContain("units/bar/buildpic/x.webp");
  expect(said).toContain("history");
  expect(said).toContain("A commit alone is not enough");
});
