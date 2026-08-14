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
  put: () => {
    throw new Error("orphan.test.ts must never spend an advanced operation");
  },
  del: () => {
    throw new Error("orphan.test.ts must never call the store");
  },
}));

const { fetchOrphans, forgetOrphans, recordUnclaimedObject, sweepOrphans } = await import(
  "./orphan"
);

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
  const table = (rows: Record<string, unknown>[]) => {
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
      is: (column: string, value: unknown) => {
        matching = matching.filter((row) => row[column] === value);
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
        (name === "asset_orphan" ? world.orphans : world.assets) as unknown as Record<
          string,
          unknown
        >[],
      ),
    rpc: (name: string, args: Record<string, unknown>) => {
      if (name === "record_unclaimed_object") {
        const path = args.object_path as string;
        if (world.orphans.some((row) => row.path === path)) {
          return Promise.resolve({ data: false, error: null });
        }
        world.orphan(path, { reason: "unclaimed", bytes: args.object_bytes as number });
        return Promise.resolve({ data: true, error: null });
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

test("an object nobody claimed is recorded once, however many times it is reported", async () => {
  const supabase = fakeSupabase(world);

  expect(await recordUnclaimedObject(supabase, "stranded.webp", 4096)).toBe(true);
  expect(await recordUnclaimedObject(supabase, "stranded.webp", 4096)).toBe(false);
  expect(world.orphans.map((row) => [row.path, row.reason])).toEqual([
    ["stranded.webp", "unclaimed"],
  ]);
});

test("recording says no rather than throwing when the database will not have it", async () => {
  const supabase = {
    rpc: () => Promise.resolve({ data: null, error: { message: "no" } }),
  } as unknown as SupabaseClient;

  expect(await recordUnclaimedObject(supabase, "stranded.webp", 4096)).toBe(false);
});
