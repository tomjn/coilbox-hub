import { beforeEach, expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
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
        (name === "staged_object_deletion"
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

      throw new Error(`no fake for ${name}`);
    },
  } as unknown as SupabaseClient;
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
