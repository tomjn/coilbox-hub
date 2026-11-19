import { beforeEach, expect, test } from "bun:test";
import type { PromotionStore } from "./jobStore";
import type { CleanupPorts } from "./orphan";

/**
 * Sweeping staging objects nothing claims (issues #113 and #335).
 *
 * One claim, and it is the only one worth testing: an unclaimed object and a
 * live one sit side by side in the bucket, and the sweep takes the unclaimed
 * one. Deletion is the single irreversible thing this code does, so every test
 * below is a variation on which of the two went.
 */

const { ABANDONED_RESERVATION_MINUTES, sweepStagedObjects } = await import("./orphan");

interface AssetRow {
  path: string;
  tier: string;
  blob_path: string | null;
}

/** The store, the table, and what a sweep did to both. */
class World {
  assets: AssetRow[] = [];
  said: string[] = [];
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
}

/**
 * As much of the hub's promotion API as this module asks for. The hub works out
 * which reservations are abandoned from its own clock, so the fake takes `now`.
 */
function fakeStore(world: World, now: Date): PromotionStore {
  const unused = async (): Promise<never> => {
    throw new Error("the sweep does not ask for this");
  };
  const before = new Date(now.getTime() - ABANDONED_RESERVATION_MINUTES * 60 * 1000).toISOString();

  return {
    unclaimed: async (limit) => {
      const data = [...world.bucket]
        .filter((path) => !world.claims(path))
        .slice(0, limit)
        .map((path) => ({ path, bytes: 1024, created_at: "2026-09-14T00:00:00.000Z" }));
      world.afterListing?.();
      return data;
    },
    abandoned: async () =>
      [...world.reservations].filter(([, reservedAt]) => reservedAt < before).map(([path]) => path),
    reserve: async (paths, queuedIsClaimed) =>
      [...new Set(paths)]
        .filter((path) => !world.claims(path, queuedIsClaimed))
        .map((path) => {
          if (!world.reservations.has(path)) world.reservations.set(path, "2026-09-14T12:00:00.000Z");
          return path;
        }),
    release: async (paths) => {
      let released = 0;
      for (const path of paths) {
        if (world.reservations.delete(path)) released++;
      }
      return released;
    },
    withdrawals: unused,
    recordWithdrawn: unused,
    due: unused,
    stillDue: unused,
    pendingDeletions: unused,
    promote: unused,
    clearPromoted: unused,
    readStaged: unused,
    discard: unused,
    games: unused,
    clearGameStagedTier: unused,
    meters: unused,
  };
}

function fakePorts(world: World): CleanupPorts {
  return {
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

let world: World;

beforeEach(() => {
  world = new World();
});

// ## The staged pictures (#335, #616)
//
// Nothing is queued: the hub lists what nothing claims, and a deletion is
// reserved first, under a lock an upload also takes. The database half is
// `PromotionApiTest.php` in the hub. This half is the order.

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

  const result = await sweepStagedObjects(fakeStore(world, NOW), fakePorts(world), 200);

  expect(result).toEqual({ deleted: 1, kept: 0 });
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/spare.webp"]);
  expect(world.reservations.size).toBe(0);
  bucketInvariant(world);
});

test("an object an upload claims after the bucket was listed is kept", async () => {
  world.bucket.add("units/bar/buildpic/reused.webp");
  world.afterListing = () => {
    world.assets.push({ path: "units/bar/buildpic/reused.webp", tier: "bucket", blob_path: null });
  };

  const result = await sweepStagedObjects(fakeStore(world, NOW), fakePorts(world), 200);

  expect(result).toEqual({ deleted: 0, kept: 1 });
  expect(world.discardedStaged).toEqual([]);
  expect(world.said).toEqual(["keep units/bar/buildpic/reused.webp: a row claimed it after the bucket was listed."]);
  bucketInvariant(world);
});

test("a sweep killed at the delete leaves the reservation, and a sweep after the job's timeout finishes it", async () => {
  world.bucket.add("units/bar/buildpic/spare.webp");
  world.discardStagedFails = true;

  await expect(sweepStagedObjects(fakeStore(world, NOW), fakePorts(world), 200)).rejects.toThrow("would not answer");

  // Still reserved, so the database keeps refusing an upload that would reuse it.
  expect(world.reservations.has("units/bar/buildpic/spare.webp")).toBe(true);
  expect(world.bucket.has("units/bar/buildpic/spare.webp")).toBe(true);
  bucketInvariant(world);

  // The object itself is gone by the next sweep only if the delete landed, so
  // take the worst case: it did, and only the reservation is left.
  world.bucket.delete("units/bar/buildpic/spare.webp");
  const soon = await sweepStagedObjects(fakeStore(world, NOW), fakePorts(world), 200);
  expect(soon).toEqual({ deleted: 0, kept: 0 });
  expect(world.reservations.size).toBe(1);

  const later = new Date(NOW.getTime() + (ABANDONED_RESERVATION_MINUTES + 1) * 60 * 1000);
  const result = await sweepStagedObjects(fakeStore(world, later), fakePorts(world), 200);

  expect(result).toEqual({ deleted: 1, kept: 0 });
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/spare.webp"]);
  expect(world.reservations.size).toBe(0);
  expect(world.said).toContain("Finished deleting 1 bucket object(s) an earlier run reserved.");
});
