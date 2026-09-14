import { beforeEach, expect, mock, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { CleanupPorts } from "./orphan";

/**
 * Sweeping staging objects nothing claims (issue #113).
 *
 * One claim, and it is the only one worth testing: an orphan and a live object
 * sit side by side in the store, and the sweep takes the orphan. Deletion is the
 * single irreversible thing this code does, so every test below is a variation
 * on which of the two went.
 *
 * The second claim is about order. Delete, then forget. A sweep killed between
 * the two leaves an entry naming an object that is already gone, which the next
 * sweep deletes again for free. Killed the other way round it would leave an
 * object nothing names, and `list()` is banned, so nothing could ever find it.
 */

// Nothing here reaches the store. The module under test never imports the SDK,
// and this makes that structural rather than something to check by reading.
mock.module("@vercel/blob", () => ({
  BlobStoreSuspendedError: class extends Error {},
  put: () => {
    throw new Error("orphan.test.ts must never spend an advanced operation");
  },
  del: () => {
    throw new Error("orphan.test.ts must never call the store");
  },
}));

const {
  ABANDONED_RESERVATION_MINUTES,
  claimedPaths,
  fetchOrphans,
  forgetOrphans,
  stagingPathsInUse,
  sweepOrphans,
  sweepStagedObjects,
} = await import("./orphan");

interface AssetRow {
  path: string;
  tier: string;
  blob_path: string | null;
}

interface OrphanRow {
  id: number;
  path: string;
  bytes: number;
  reason: string;
  at: string;
  deleted_at: string | null;
}

/** The store, the table, and what a sweep did to both. */
class World {
  assets: AssetRow[] = [];
  orphans: OrphanRow[] = [];
  /** Objects actually in the staging store, by pathname. */
  blob = new Set<string>();
  discarded: string[] = [];
  said: string[] = [];
  /** Set to make `discard` throw, which is how a sweep is killed mid-run. */
  discardFails = false;
  /** Size of every `.in()` call made against `assets`, in request order. */
  inBatchSizes: number[] = [];
  /** Objects in the Supabase bucket, by path. */
  bucket = new Set<string>();
  /** Game pictures whose staged copy the row says is in the bucket. */
  stagedGamePaths: string[] = [];
  /** Outstanding bucket deletion reservations, path to when reserved. */
  reservations = new Map<string, string>();
  discardedStaged: string[] = [];
  /** Set to make `discardStaged` throw once. */
  discardStagedFails = false;
  /** Runs between the bucket listing and the reservation, to race a claim in. */
  afterListing: (() => void) | null = null;

  /** Whether anything claims a bucket path, the way the two SQL functions in
   *  20260914170000 decide it. */
  claims(path: string, queuedIsClaimed = true) {
    return (
      this.assets.some((row) => row.path === path && row.tier !== "static") ||
      (queuedIsClaimed && this.assets.some((row) => row.blob_path === path)) ||
      this.stagedGamePaths.includes(path)
    );
  }

  live(path: string) {
    this.assets.push({ path, tier: "blob", blob_path: null });
    this.blob.add(path);
    return this;
  }

  queued(path: string, blobPath: string) {
    this.assets.push({ path, tier: "static", blob_path: blobPath });
    this.blob.add(blobPath);
    return this;
  }

  orphan(path: string, over: Partial<OrphanRow> = {}) {
    this.orphans.push({
      id: this.orphans.length + 1,
      path,
      bytes: 4096,
      reason: "superseded",
      at: `2026-08-${String(10 + this.orphans.length).padStart(2, "0")}T00:00:00.000Z`,
      deleted_at: null,
      ...over,
    });
    this.blob.add(path);
    return this;
  }
}

function pick(row: Record<string, unknown>, columns: string[]): Record<string, unknown> {
  return Object.fromEntries(columns.map((column) => [column, row[column]]));
}

/** As much of PostgREST as this module asks for. */
function fakeSupabase(world: World): SupabaseClient {
  const table = (name: string, rows: Record<string, unknown>[]) => {
    let matching = rows;
    let columns: string[] = [];

    const builder = {
      select: (list: string) => {
        columns = list.split(",").map((column) => column.trim());
        return builder;
      },
      eq: (column: string, value: unknown) => {
        matching = matching.filter((row) => row[column] === value);
        return builder;
      },
      neq: (column: string, value: unknown) => {
        matching = matching.filter((row) => row[column] !== value);
        return builder;
      },
      is: (column: string, value: unknown) => {
        matching = matching.filter((row) => row[column] === value);
        return builder;
      },
      lt: (column: string, value: string) => {
        matching = matching.filter((row) => String(row[column]) < value);
        return builder;
      },
      in: (column: string, values: unknown[]) => {
        if (name === "asset") world.inBatchSizes.push(values.length);
        matching = matching.filter((row) => values.includes(row[column]));
        return builder;
      },
      order: () => builder,
      limit: (n: number) => {
        matching = matching.slice(0, n);
        return builder;
      },
      then: (resolve: (value: { data: Record<string, unknown>[]; error: null }) => unknown) =>
        resolve({ data: matching.map((row) => pick(row, columns)), error: null }),
    };

    return builder;
  };

  return {
    from: (name: string) =>
      table(
        name,
        (name === "asset_orphan"
          ? world.orphans
          : name === "staged_object_deletion"
            ? [...world.reservations].map(([path, reserved_at]) => ({ path, reserved_at }))
            : world.assets) as unknown as Record<string, unknown>[],
      ),
    rpc: (name: string, args: Record<string, unknown>) => {
      if (name === "unclaimed_staged_objects") {
        const data = [...world.bucket]
          .filter((path) => !world.claims(path))
          .slice(0, args.max_objects as number)
          .map((path) => ({ object_path: path, bytes: 1024, created_at: "2026-09-14T00:00:00.000Z" }));
        world.afterListing?.();
        return Promise.resolve({ data, error: null });
      }
      if (name === "reserve_staged_deletions") {
        const data = [...new Set(args.object_paths as string[])]
          .filter((path) => !world.claims(path, args.queued_is_claimed as boolean))
          .map((path) => {
            if (!world.reservations.has(path)) world.reservations.set(path, "2026-09-14T12:00:00.000Z");
            return { object_path: path };
          });
        return Promise.resolve({ data, error: null });
      }
      if (name === "release_staged_deletions") {
        let released = 0;
        for (const path of args.object_paths as string[]) {
          if (world.reservations.delete(path)) released++;
        }
        return Promise.resolve({ data: released, error: null });
      }

      let cleared = 0;
      for (const row of world.orphans) {
        if ((args.ids as number[]).includes(row.id) && row.deleted_at === null) {
          row.deleted_at = "2026-08-14T12:00:00.000Z";
          cleared++;
        }
      }
      return Promise.resolve({ data: cleared, error: null });
    },
  } as unknown as SupabaseClient;
}

function fakePorts(world: World): CleanupPorts {
  return {
    discard: async (paths) => {
      if (world.discardFails) throw new Error("the store would not answer");
      for (const path of paths) {
        world.blob.delete(path);
        world.discarded.push(path);
      }
    },
    discardStaged: async (paths) => {
      if (world.discardStagedFails) {
        world.discardStagedFails = false;
        throw new Error("the bucket would not answer");
      }
      for (const path of paths) {
        world.bucket.delete(path);
        world.discardedStaged.push(path);
      }
    },
    say: (message) => {
      world.said.push(message);
    },
  };
}

/** The invariant a sweep must never break: every object left in the store is
 *  still named by something, either a row or an outstanding queue entry. */
function invariant(world: World) {
  for (const path of world.blob) {
    const named =
      world.assets.some((row) => row.path === path || row.blob_path === path) ||
      world.orphans.some((row) => row.path === path && row.deleted_at === null);
    expect({ path, named }).toEqual({ path, named: true });
  }
}

let world: World;

beforeEach(() => {
  world = new World();
});

test("the orphan goes and the live object beside it stays", async () => {
  world.live("units/bar/buildpic/new-Zx91Kp2w.webp").orphan("units/bar/buildpic/old-Hn4vQ2rT.webp");

  const result = await sweepOrphans(fakeSupabase(world), fakePorts(world));

  expect(result).toEqual({ deleted: 1, kept: 0 });
  expect(world.discarded).toEqual(["units/bar/buildpic/old-Hn4vQ2rT.webp"]);
  expect([...world.blob]).toEqual(["units/bar/buildpic/new-Zx91Kp2w.webp"]);
  invariant(world);
});

test("the entry is settled once the object is gone, so a second sweep does nothing", async () => {
  world.live("live.webp").orphan("old.webp");
  const supabase = fakeSupabase(world);

  await sweepOrphans(supabase, fakePorts(world));
  expect(world.orphans[0].deleted_at).not.toBeNull();

  const again = await sweepOrphans(supabase, fakePorts(world));
  expect(again).toEqual({ deleted: 0, kept: 0 });
  expect(world.discarded).toEqual(["old.webp"]);
});

test("an object a row names again is kept, whatever the queue says", async () => {
  world.live("recycled.webp").orphan("recycled.webp");

  const result = await sweepOrphans(fakeSupabase(world), fakePorts(world));

  expect(result).toEqual({ deleted: 0, kept: 1 });
  expect(world.discarded).toEqual([]);
  expect(world.said).toEqual(["keep recycled.webp: a row names it, so it is not an orphan."]);
  invariant(world);
});

test("a path a bucket row names is never swept, even if the queue somehow holds it", async () => {
  // Nothing queues a bucket path today: the trigger only records Blob paths.
  // This is the backstop for whichever store the sweep deletes from after #335.
  world.assets.push({ path: "units/bar/buildpic/enc-a.webp", tier: "bucket", blob_path: null });
  world.orphan("units/bar/buildpic/enc-a.webp");

  const result = await sweepOrphans(fakeSupabase(world), fakePorts(world));

  expect(result).toEqual({ deleted: 0, kept: 1 });
  expect(world.discarded).toEqual([]);
});

test("promotion's own drain queue is left to promotion", async () => {
  // A promoted row whose staging copy has not been deleted yet is class three,
  // and `lib/assets/promote.ts` deletes it only once the durable tier is
  // serving the bytes. A sweep that took it would be a second deleter without
  // that gate.
  world.queued("units/bar/buildpic/enc-a.webp", "units/bar/buildpic/enc-a-Hn4vQ2rT.webp");
  world.orphan("units/bar/buildpic/enc-a-Hn4vQ2rT.webp");

  const result = await sweepOrphans(fakeSupabase(world), fakePorts(world));

  expect(result).toEqual({ deleted: 0, kept: 1 });
  expect(world.discarded).toEqual([]);
  invariant(world);
});

test("an empty queue touches neither the store nor the table", async () => {
  world.live("live.webp");

  const result = await sweepOrphans(fakeSupabase(world), fakePorts(world));

  expect(result).toEqual({ deleted: 0, kept: 0 });
  expect(world.discarded).toEqual([]);
  expect(world.said).toEqual([]);
});

test("a sweep killed at the delete leaves the entry, and the next one finishes it", async () => {
  world.live("live.webp").orphan("old.webp");
  const supabase = fakeSupabase(world);

  world.discardFails = true;
  await expect(sweepOrphans(supabase, fakePorts(world))).rejects.toThrow("would not answer");

  expect(world.orphans[0].deleted_at).toBeNull();
  invariant(world);

  world.discardFails = false;
  expect(await sweepOrphans(supabase, fakePorts(world))).toEqual({ deleted: 1, kept: 0 });
  expect([...world.blob]).toEqual(["live.webp"]);
});

test("only what is still outstanding comes back, oldest first", async () => {
  world.orphan("first.webp").orphan("second.webp", { deleted_at: "2026-08-01T00:00:00.000Z" });

  const outstanding = await fetchOrphans(fakeSupabase(world));

  expect(outstanding.map((orphan) => orphan.path)).toEqual(["first.webp"]);
});

test("forgetting nothing asks the database nothing", async () => {
  const supabase = {
    rpc: () => {
      throw new Error("should not have been called");
    },
  } as unknown as SupabaseClient;

  expect(await forgetOrphans(supabase, [])).toBe(0);
});

test("a long list of pathnames is asked about in batches, not one request", async () => {
  // #300: a queue of 200 pending deletions was enough to trip Supabase's
  // gateway with a 400 before a single row-count answer came back. Every
  // request has to stay well under that regardless of how large the queue
  // gets.
  const paths = Array.from({ length: 120 }, (_, i) => `units/bar/buildpic/${i}.webp`);
  for (const path of paths) world.live(path);

  const inUse = await stagingPathsInUse(fakeSupabase(world), paths);

  expect(inUse.size).toBe(120);
  expect(world.inBatchSizes.length).toBeGreaterThan(1);
  expect(world.inBatchSizes.every((size) => size <= 50)).toBe(true);
  expect(world.inBatchSizes.reduce((a, b) => a + b, 0)).toBe(120);
});

test("claimedPaths batches both the live check and the queue check", async () => {
  const paths = Array.from({ length: 120 }, (_, i) => `units/bar/buildpic/${i}.webp`);
  for (const [i, path] of paths.entries()) {
    if (i % 2 === 0) world.live(path);
    else world.queued(`row-${i}.webp`, path);
  }

  const claimed = await claimedPaths(fakeSupabase(world), paths);

  expect(claimed.size).toBe(120);
  expect(world.inBatchSizes.every((size) => size <= 50)).toBe(true);
});

// ## The Supabase bucket (#335)
//
// Nothing is queued: the bucket is listed from Postgres, and a deletion is
// reserved first, under a lock an upload also takes. The SQL half, including
// the lock, is `staged_pictures_promotion.test.sql`. This half is the order.

const NOW = new Date("2026-09-14T12:00:00.000Z");

/** The bucket invariant: every object left is claimed, or reserved for a
 *  deletion that has not finished. */
function bucketInvariant(world: World) {
  for (const path of world.bucket) {
    const named = world.claims(path) || world.reservations.has(path);
    expect({ path, named }).toEqual({ path, named: true });
  }
}

test("the bucket sweep takes what nothing claims and leaves a shared object two rows name", async () => {
  const shared = "units/bar/buildpic/shared.webp";
  world.assets.push({ path: shared, tier: "bucket", blob_path: null });
  world.assets.push({ path: shared, tier: "bucket", blob_path: null });
  world.assets.push({ path: "units/bar/buildpic/done.webp", tier: "static", blob_path: "units/bar/buildpic/queued.webp" });
  world.stagedGamePaths.push("games/BA/logo.webp");
  for (const path of [shared, "units/bar/buildpic/spare.webp", "units/bar/buildpic/queued.webp", "games/BA/logo.webp"]) {
    world.bucket.add(path);
  }

  const result = await sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, NOW);

  expect(result).toEqual({ deleted: 1, kept: 0 });
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/spare.webp"]);
  expect(world.discarded).toEqual([]);
  expect(world.reservations.size).toBe(0);
  bucketInvariant(world);
});

test("an object an upload claims after the bucket was listed is kept", async () => {
  world.bucket.add("units/bar/buildpic/reused.webp");
  world.afterListing = () => {
    world.assets.push({ path: "units/bar/buildpic/reused.webp", tier: "bucket", blob_path: null });
  };

  const result = await sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, NOW);

  expect(result).toEqual({ deleted: 0, kept: 1 });
  expect(world.discardedStaged).toEqual([]);
  expect(world.said).toEqual(["keep units/bar/buildpic/reused.webp: a row claimed it after the bucket was listed."]);
  bucketInvariant(world);
});

test("a sweep killed at the delete leaves the reservation, and a sweep after the job's timeout finishes it", async () => {
  world.bucket.add("units/bar/buildpic/spare.webp");
  world.discardStagedFails = true;

  await expect(sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, NOW)).rejects.toThrow("would not answer");

  // Still reserved, so the database keeps refusing an upload that would reuse it.
  expect(world.reservations.has("units/bar/buildpic/spare.webp")).toBe(true);
  expect(world.bucket.has("units/bar/buildpic/spare.webp")).toBe(true);
  bucketInvariant(world);

  // The object itself is gone by the next sweep only if the delete landed, so
  // take the worst case: it did, and only the reservation is left.
  world.bucket.delete("units/bar/buildpic/spare.webp");
  const soon = await sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, NOW);
  expect(soon).toEqual({ deleted: 0, kept: 0 });
  expect(world.reservations.size).toBe(1);

  const later = new Date(NOW.getTime() + (ABANDONED_RESERVATION_MINUTES + 1) * 60 * 1000);
  const result = await sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, later);

  expect(result).toEqual({ deleted: 1, kept: 0 });
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/spare.webp"]);
  expect(world.reservations.size).toBe(0);
  expect(world.said).toContain("Finished deleting 1 bucket object(s) an earlier run reserved.");
});

test("the Blob sweep never deletes from the bucket, and the bucket sweep never deletes from Blob", async () => {
  world.live("live.webp").orphan("old-Hn4vQ2rT.webp");
  world.bucket.add("old-Hn4vQ2rT.webp");

  await sweepOrphans(fakeSupabase(world), fakePorts(world));
  expect(world.discarded).toEqual(["old-Hn4vQ2rT.webp"]);
  expect(world.discardedStaged).toEqual([]);

  world.blob.add("stray.webp");
  await sweepStagedObjects(fakeSupabase(world), fakePorts(world), 200, NOW);
  expect(world.discardedStaged).toEqual(["old-Hn4vQ2rT.webp"]);
  expect(world.blob.has("stray.webp")).toBe(true);
});
