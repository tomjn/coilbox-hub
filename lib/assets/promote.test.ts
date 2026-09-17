import { beforeEach, expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  PROMOTION_AGE_DAYS,
  StagingReadError,
  durablePath,
  promotionCutoff,
  runPromotion,
  type PromotionPorts,
} from "./promote";

/**
 * The promotion job (issue #111), and mostly one claim about it: an
 * interrupted run leaves a picture in both tiers and never in neither.
 *
 * Asserting that in a comment is worth nothing, so the run is killed at each of
 * its side effects in turn and the world is inspected afterwards. Two
 * invariants are checked every time, and they are the whole of the guarantee:
 *
 * - every row's bytes are reachable at the tier and path the row names
 * - every object in the staging store is still named by some row, either as its
 *   current path or as the `blob_path` it is queued for deletion under
 *
 * The second one is the one that needs a column to hold it up. Nothing here
 * lists the bucket, so #113 finds orphans by enumerating from Postgres, and an
 * object no row names is an object nothing can ever find again.
 *
 * After each kill the run is restarted and has to converge, because a guarantee
 * that leaves the job wedged is not much of one.
 */

const NOW = new Date("2026-08-14T12:00:00.000Z");
const OLD = new Date("2026-08-01T12:00:00.000Z").toISOString();
const RECENT = new Date("2026-08-14T11:00:00.000Z").toISOString();

interface Row {
  id: string;
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  hash: string;
  mime: string;
  path: string;
  tier: string;
  moderation: string;
  rejection_kind: string | null;
  bytes: number;
  blob_path: string | null;
  promoted_at: string | null;
  updated_at: string;
  bytes_missing_at: string | null;
}

/** A unit picture staged in the bucket, where the staging path is already the
 *  durable one. */
function unit(id: string, name: string, hash: string, over: Partial<Row> = {}): Row {
  return {
    id,
    game: "bar",
    unit_name: name,
    map_name: null,
    variant: "buildpic",
    hash,
    mime: "image/webp",
    path: `units/bar/buildpic/${hash}.webp`,
    tier: "bucket",
    moderation: "approved",
    rejection_kind: null,
    bytes: 4096,
    blob_path: null,
    promoted_at: null,
    updated_at: OLD,
    bytes_missing_at: null,
    ...over,
  };
}

/**
 * Everything outside the process: the table, the store, the assets checkout and
 * what the published site is actually serving.
 */
class World {
  rows: Row[];
  /** Objects in the Supabase bucket, by path. */
  bucket = new Set<string>();
  /** Outstanding bucket deletion reservations, by path. */
  reservations = new Set<string>();
  discardedStaged: string[] = [];
  /** Written into the checkout but not pushed. A throwaway working tree. */
  checkout = new Set<string>();
  /** On the default branch of the assets repo. */
  pushed = new Set<string>();
  /** What the published site answers for. */
  served = new Set<string>();
  /** Which port or function throws the next time it is called. */
  failAt: string | null = null;
  said: string[] = [];
  /** Off, and the run must then move nothing. */
  deploys = true;

  constructor(rows: Row[]) {
    this.rows = rows;
    for (const row of rows) {
      if (row.tier === "bucket") this.bucket.add(row.path);
      if (row.tier === "static") this.pushed.add(row.path);
      if (row.blob_path) this.bucket.add(row.blob_path);
    }
    for (const path of this.pushed) this.served.add(path);
  }

  trip(port: string) {
    if (this.failAt === port) {
      this.failAt = null;
      throw new Error(`killed at ${port}`);
    }
  }
}

/**
 * As much of PostgREST as this module asks for, over an array of rows.
 *
 * The two functions are reimplemented rather than stubbed, closely enough that
 * the filters which make the batch safe are exercised here.
 * `asset_promotion.test.sql` is what proves the real statement is one
 * transaction and that the safety trigger refuses it, which no fake can.
 */
function fakeSupabase(world: World): SupabaseClient {
  const query = (rows: Row[]) => {
    let matching = rows;
    const builder = {
      select: () => builder,
      order: () => builder,
      limit: (n: number) => {
        matching = matching.slice(0, n);
        return builder;
      },
      eq: (col: keyof Row, value: unknown) => {
        matching = matching.filter((row) => row[col] === value);
        return builder;
      },
      is: (col: keyof Row, value: unknown) => {
        matching = matching.filter((row) => row[col] === value);
        return builder;
      },
      neq: (col: keyof Row, value: unknown) => {
        matching = matching.filter((row) => row[col] !== value);
        return builder;
      },
      not: (col: keyof Row, _op: string, value: unknown) => {
        matching = matching.filter((row) => row[col] !== value);
        return builder;
      },
      lte: (col: keyof Row, value: string) => {
        matching = matching.filter((row) => String(row[col]) <= value);
        return builder;
      },
      in: (col: keyof Row, values: unknown[]) => {
        matching = matching.filter((row) => values.includes(row[col]));
        return builder;
      },
      then: (resolve: (value: { data: Row[]; error: null }) => unknown) =>
        resolve({ data: matching.map((row) => ({ ...row })), error: null }),
    };
    return builder;
  };

  const promote = (ids: string[], paths: string[]) => {
    const moved: { id: string; blob_path: string }[] = [];

    ids.forEach((id, index) => {
      const row = world.rows.find((candidate) => candidate.id === id);
      if (!row) return;
      if (row.tier !== "bucket") return;
      if (row.moderation !== "approved") return;
      if (row.blob_path !== null) return;
      if (row.bytes_missing_at !== null) return;

      row.blob_path = row.path;
      row.path = paths[index];
      row.tier = "static";
      row.promoted_at = NOW.toISOString();
      moved.push({ id, blob_path: row.blob_path });
    });

    return moved;
  };

  const clear = (ids: string[]) => {
    let cleared = 0;
    for (const row of world.rows) {
      if (ids.includes(row.id) && row.blob_path !== null) {
        row.blob_path = null;
        cleared++;
      }
    }
    return cleared;
  };

  // The claims 20260914170000 checks under its lock.
  const reserve = (paths: string[], queuedIsClaimed: boolean) =>
    [...new Set(paths)]
      .filter(
        (path) =>
          !world.rows.some((row) => row.path === path && row.tier !== "static") &&
          !(queuedIsClaimed && world.rows.some((row) => row.blob_path === path)),
      )
      .map((path) => {
        world.reservations.add(path);
        return { object_path: path };
      });

  const release = (paths: string[]) => paths.filter((path) => world.reservations.delete(path)).length;

  return {
    from: () => query(world.rows),
    rpc: (name: string, args: Record<string, unknown>) => {
      world.trip(name);

      const data =
        name === "promote_assets"
          ? promote(args.ids as string[], args.paths as string[])
          : name === "reserve_staged_deletions"
            ? reserve(args.object_paths as string[], args.queued_is_claimed as boolean)
            : name === "release_staged_deletions"
              ? release(args.object_paths as string[])
              : clear(args.ids as string[]);

      return Promise.resolve({ data, error: null });
    },
  } as unknown as SupabaseClient;
}

function fakePorts(world: World): PromotionPorts {
  return {
    readStaged: async (path: string) => {
      world.trip("readStaged");
      const row = world.rows.find((candidate) => candidate.path === path);
      if (!world.bucket.has(path) || !row) throw new StagingReadError(404, path);
      return new Uint8Array(row.bytes);
    },
    held: async (path: string) => world.checkout.has(path) || world.pushed.has(path),
    write: async (path: string, bytes: Uint8Array) => {
      world.trip("write");
      expect(bytes.byteLength).toBeGreaterThan(0);
      world.checkout.add(path);
    },
    // Only the game picture pass removes anything.
    remove: async (path: string) => {
      throw new Error(`the asset pass removed ${path}`);
    },
    publish: async () => {
      world.trip("publish");
      for (const path of world.checkout) {
        world.pushed.add(path);
        if (world.deploys) world.served.add(path);
      }
      world.checkout.clear();
    },
    serving: async (paths: string[]) => {
      world.trip("serving");
      return paths.filter((path) => world.served.has(path));
    },
    discardStaged: async (paths: string[]) => {
      world.trip("discardStaged");
      for (const path of paths) {
        world.bucket.delete(path);
        world.discardedStaged.push(path);
      }
    },
    say: (message: string) => {
      world.said.push(message);
    },
  };
}

/** The two things that must be true of the world at every instant. */
function invariants(world: World) {
  for (const row of world.rows) {
    const where = row.tier === "bucket" ? world.bucket : world.pushed;
    expect({ id: row.id, tier: row.tier, reachable: where.has(row.path) }).toEqual({
      id: row.id,
      tier: row.tier,
      reachable: true,
    });
  }

  // A bucket object may also be reserved by a run that died mid delete, which
  // keeps refusing any upload that would claim it.
  for (const path of world.bucket) {
    const named =
      world.reservations.has(path) ||
      world.rows.some((row) => (row.tier === "bucket" && row.path === path) || row.blob_path === path);
    expect({ path, named }).toEqual({ path, named: true });
  }
}

let world: World;

beforeEach(() => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1"),
    unit("00000000-0000-4000-8000-000000000002", "armllt", "bbb2"),
  ]);
});

const run = (options: { limit?: number } = {}) =>
  runPromotion(fakeSupabase(world), fakePorts(world), { now: NOW, ...options });

test("the cutoff is a day back, on the row's own clock", () => {
  expect(promotionCutoff(NOW)).toBe("2026-08-13T12:00:00.000Z");
  expect(PROMOTION_AGE_DAYS).toBe(1);
});

test("the durable path is recomputed from identity and hash", () => {
  const row = unit("00000000-0000-4000-8000-00000000000f", "armsolar", "aaa1");

  expect(durablePath({ ...row, tier: "bucket" })).toBe("units/bar/buildpic/aaa1.webp");
});

test("a whole run moves the rows and empties the staging store", async () => {
  const result = await run();

  expect(result).toEqual({ drained: 0, promoted: 2, skipped: 0, deleted: 2, unreadable: 0 });
  expect([...world.pushed].sort()).toEqual([
    "units/bar/buildpic/aaa1.webp",
    "units/bar/buildpic/bbb2.webp",
  ]);
  expect([...world.bucket]).toEqual([]);
  expect(world.rows.map((row) => `${row.tier} ${row.path} ${row.blob_path}`)).toEqual([
    "static units/bar/buildpic/aaa1.webp null",
    "static units/bar/buildpic/bbb2.webp null",
  ]);
  expect(world.rows.every((row) => row.promoted_at !== null)).toBe(true);
  invariants(world);
});

test("nothing approved in the last day moves", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", { updated_at: RECENT }),
  ]);

  expect(await run()).toEqual({ drained: 0, promoted: 0, skipped: 0, deleted: 0, unreadable: 0 });
  expect(world.rows[0].tier).toBe("bucket");
  expect(world.discardedStaged).toEqual([]);
});

test("a bucket object the store does not have skips its row and deletes nothing", async () => {
  world = new World([unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1")]);
  world.bucket.clear();

  const result = await run();

  expect(result).toEqual({ drained: 0, promoted: 0, skipped: 1, deleted: 0, unreadable: 1 });
  expect(world.rows[0].tier).toBe("bucket");
  expect(world.said.join("\n")).toContain("the store would not return its bytes: 404");
});

test("a bucket object two rows share is kept until the second row has moved too", async () => {
  const shared = "units/bar/buildpic/aaa1.webp";
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1"),
    unit("00000000-0000-4000-8000-000000000002", "armadvsol", "aaa1", { updated_at: RECENT }),
  ]);

  const first = await run();

  expect(first).toMatchObject({ promoted: 1, deleted: 0 });
  expect(world.bucket.has(shared)).toBe(true);
  expect(world.rows[0]).toMatchObject({ blob_path: shared });
  expect(world.said).toContain(`keep ${shared}: another row is still serving that object.`);
  expect(world.reservations.size).toBe(0);
  invariants(world);

  world.rows[1].updated_at = OLD;
  const second = await run();

  // The first row's queue entry is not a claim against the drain, or the two
  // entries would keep the object for each other forever.
  expect(second).toMatchObject({ promoted: 1, deleted: 1 });
  expect(world.discardedStaged).toEqual([shared]);
  expect(world.rows.every((row) => row.tier === "static")).toBe(true);
  invariants(world);

  // The first row's entry outlived the delete, and the next drain settles it.
  const third = await run();

  expect(third.drained).toBe(1);
  expect(world.rows.every((row) => row.blob_path === null)).toBe(true);
  expect([...world.bucket]).toEqual([]);
  expect(world.reservations.size).toBe(0);
  invariants(world);
});

test("a promoted bucket row replaced by the same bytes before its drain settles the entry and keeps the object", async () => {
  // `checkAssetUpload` refuses only an identical source hash, so a newer
  // archive can encode to the same bytes and land back on the queued path.
  const path = "units/bar/buildpic/aaa1.webp";
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      moderation: "pending",
      updated_at: RECENT,
      blob_path: path,
    }),
  ]);

  const first = await run();

  expect(first).toMatchObject({ drained: 0, promoted: 0 });
  expect(world.discardedStaged).toEqual([]);
  expect(world.rows[0]).toMatchObject({ tier: "bucket", path, blob_path: null });
  expect(world.said).toContain(`keep ${path}: its row was replaced with the same bytes and serves it again.`);
  invariants(world);

  // And nothing stops it promoting once it is approved and past the hold.
  Object.assign(world.rows[0], { moderation: "approved", updated_at: OLD });
  const second = await run();

  expect(second).toMatchObject({ promoted: 1, deleted: 1 });
  expect([...world.bucket]).toEqual([]);
  invariants(world);
});

test("an upload that reuses a bucket object while it is being promoted keeps the object", async () => {
  // The race #332 warned about: identical bytes uploaded for another unit land
  // on the same content addressed object after the row moved and before the
  // delete. The reservation sees the new claim and refuses.
  const path = "units/bar/buildpic/aaa1.webp";
  world = new World([unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1")]);
  const supabase = fakeSupabase(world);
  const rpc = supabase.rpc.bind(supabase);
  (supabase as unknown as { rpc: typeof rpc }).rpc = ((name: string, args: Record<string, unknown>) => {
    if (name === "reserve_staged_deletions") {
      world.rows.push(
        unit("00000000-0000-4000-8000-000000000003", "armadvsol", "aaa1", {
          moderation: "pending",
          updated_at: RECENT,
        }),
      );
    }
    return rpc(name as never, args as never);
  }) as typeof rpc;

  const result = await runPromotion(supabase, fakePorts(world), { now: NOW });

  expect(result).toMatchObject({ promoted: 1, deleted: 0 });
  expect(world.discardedStaged).toEqual([]);
  expect(world.bucket.has(path)).toBe(true);
  expect(world.rows[0].blob_path).toBe(path);
  invariants(world);
});

// ## Killed at each step in turn
//
// The order in the module is drain, select, read, write, publish, serve, move,
// delete, clear. Each of these kills one of them, checks the invariants, then
// restarts the run and checks it converges.

const KILL_POINTS = [
  "readStaged",
  "write",
  "publish",
  "serving",
  "promote_assets",
  "reserve_staged_deletions",
  "discardStaged",
  "release_staged_deletions",
  "clear_promoted_blob_paths",
] as const;

for (const point of KILL_POINTS) {
  test(`killed at ${point}, every picture stays in a store and every object is still named`, async () => {
    world.failAt = point;

    // A failed read skips its row rather than stopping the run.
    if (point === "readStaged") await run();
    else await expect(run()).rejects.toThrow(`killed at ${point}`);

    invariants(world);

    for (const path of world.discardedStaged) {
      const claimed = world.rows.some((row) => row.path === path && row.tier !== "static");
      expect({ path, claimed }).toEqual({ path, claimed: false });
    }
  });

  test(`killed at ${point}, the next run finishes the job`, async () => {
    world.failAt = point;
    await run().catch(() => undefined);

    await run();

    expect(world.rows.every((row) => row.tier === "static" && row.blob_path === null)).toBe(true);
    expect([...world.bucket]).toEqual([]);
    expect(world.reservations.size).toBe(0);
    invariants(world);
  });
}

test("a run that dies after pushing does not write the same bytes again", async () => {
  world.failAt = "serving";
  await expect(run()).rejects.toThrow();

  const written: string[] = [];
  const ports = fakePorts(world);
  const write = ports.write;
  ports.write = async (path, bytes) => {
    written.push(path);
    await write(path, bytes);
  };

  await runPromotion(fakeSupabase(world), ports, { now: NOW });

  // Content addressed, so the objects the first run pushed are already the
  // right bytes at the right path. Rewriting them would be a change to a file
  // that is already published.
  expect(written).toEqual([]);
  expect(world.rows.every((row) => row.tier === "static")).toBe(true);
});

test("a rejection landing mid-run stops the row moving and spares its object", async () => {
  // The window `stillPromotable` narrows, closed at the far end by the same
  // filter inside promote_assets. Reading the bytes is what takes the time, so
  // the rejection is timed to land during it.
  const ports = fakePorts(world);
  const readStaged = ports.readStaged;
  ports.readStaged = async (path) => {
    const bytes = await readStaged(path);
    world.rows[1].moderation = "rejected";
    world.rows[1].rejection_kind = "safety";
    return bytes;
  };

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result.promoted).toBe(1);
  expect(result.skipped).toBe(1);
  expect(world.rows[1].tier).toBe("bucket");
  expect(world.bucket.has(world.rows[1].path)).toBe(true);
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/aaa1.webp"]);
  invariants(world);
});

test("a durable tier that never serves the batch moves nothing at all", async () => {
  world.deploys = false;

  await expect(run()).rejects.toThrow("is serving 0");

  expect(world.rows.every((row) => row.tier === "bucket")).toBe(true);
  expect(world.discardedStaged).toEqual([]);
  invariants(world);
});

test("a staging object left by an earlier run is drained before anything else", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      tier: "static",
      path: "units/bar/buildpic/aaa1.webp",
      blob_path: "units/bar/buildpic/aaa1.webp",
      promoted_at: OLD,
    }),
  ]);

  const result = await run();

  expect(result.drained).toBe(1);
  expect([...world.bucket]).toEqual([]);
  expect(world.rows[0].blob_path).toBe(null);
  invariants(world);
});

test("a leftover the durable tier is not serving is kept rather than deleted", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      tier: "static",
      path: "units/bar/buildpic/aaa1.webp",
      blob_path: "units/bar/buildpic/aaa1.webp",
      promoted_at: OLD,
    }),
  ]);
  world.served.clear();

  const result = await run();

  expect(result.drained).toBe(0);
  expect(world.bucket.has("units/bar/buildpic/aaa1.webp")).toBe(true);
  expect(world.rows[0].blob_path).toBe("units/bar/buildpic/aaa1.webp");
  expect(world.said).toContain(
    "keep units/bar/buildpic/aaa1.webp: the durable tier is not serving units/bar/buildpic/aaa1.webp yet.",
  );
});

test("a leftover from a row a newer archive replaced is deleted without a gate", async () => {
  // The row went back to the staging tier, so its `path` is a staging path and
  // there is no durable copy to confirm. The queued object is simply the
  // superseded one, and only `blob_path` still names it.
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "ccc3", {
      updated_at: RECENT,
      blob_path: "units/bar/buildpic/aaa1.webp",
    }),
  ]);

  const result = await run();

  expect(result.drained).toBe(1);
  expect(world.discardedStaged).toEqual(["units/bar/buildpic/aaa1.webp"]);
  expect(world.bucket.has(world.rows[0].path)).toBe(true);
  invariants(world);
});

test("bytes that are not the length the row claims are never committed", async () => {
  world = new World([unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1")]);

  const ports = fakePorts(world);
  ports.readStaged = async () => new Uint8Array(11);

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result).toEqual({ drained: 0, promoted: 0, skipped: 1, deleted: 0, unreadable: 0 });
  expect([...world.pushed]).toEqual([]);
  expect(world.rows[0].tier).toBe("bucket");
  expect(world.said[0]).toContain("the store returned 11 bytes and the row says 4096");
});

test("an object the store will not return skips its row and the rest of the batch moves", async () => {
  // What a suspended store did to every run from 2026-09-13: one refusal on the
  // first row ended the whole job, and nothing behind it ever moved.
  const ports = fakePorts(world);
  const readStaged = ports.readStaged;
  ports.readStaged = async (path) => {
    if (path === "units/bar/buildpic/aaa1.webp") throw new StagingReadError(403, path);
    return readStaged(path);
  };

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result).toEqual({ drained: 0, promoted: 1, skipped: 1, deleted: 1, unreadable: 1 });
  expect(world.rows[0].tier).toBe("bucket");
  expect(world.rows[1].tier).toBe("static");
  expect(world.said.join("\n")).toContain("the store would not return its bytes: 403 reading");
  invariants(world);

  // And once the store answers again, the row it skipped moves like any other.
  const next = await run();
  expect(next.promoted).toBe(1);
  expect(world.rows.every((row) => row.tier === "static")).toBe(true);
  invariants(world);
});

test("a store that refuses every read moves nothing and loses nothing", async () => {
  const ports = fakePorts(world);
  ports.readStaged = async (path) => {
    throw new StagingReadError(403, path);
  };

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result).toEqual({ drained: 0, promoted: 0, skipped: 2, deleted: 0, unreadable: 2 });
  expect(world.discardedStaged).toEqual([]);
  invariants(world);
});

/** #336. Marked once by a person after the row is found to be unreadable, so
 * the run neither reads it nor fails on it, and it cannot hold a batch slot
 * that a readable row behind it needs. */
test("a row marked as lost in the store is not selected, so it cannot fill a batch", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      bytes_missing_at: "2026-09-14T12:00:00Z",
    }),
    unit("00000000-0000-4000-8000-000000000002", "armllt", "bbb2"),
  ]);
  world.bucket.delete(world.rows[0].path);
  const read: string[] = [];
  const ports = fakePorts(world);
  const reading = ports.readStaged;
  ports.readStaged = async (path) => {
    read.push(path);
    return reading(path);
  };

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW, limit: 1 });

  expect(result).toEqual({ drained: 0, promoted: 1, skipped: 0, deleted: 1, unreadable: 0 });
  expect(read).toEqual(["units/bar/buildpic/bbb2.webp"]);
  expect(world.rows.map((row) => row.tier)).toEqual(["bucket", "static"]);
});

test("a drain that fails is said out loud and the rows due today still move", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      tier: "static",
      path: "units/bar/buildpic/aaa1.webp",
      blob_path: "units/bar/buildpic/aaa1.webp",
      promoted_at: OLD,
    }),
    unit("00000000-0000-4000-8000-000000000002", "armllt", "bbb2"),
  ]);
  world.failAt = "discardStaged";

  const result = await run();

  expect(result.drained).toBe(0);
  expect(result.promoted).toBe(1);
  expect(world.said.join("\n")).toContain("Could not drain what an earlier run left");
  // Still named, so the next run finishes the job.
  expect(world.rows[0].blob_path).toBe("units/bar/buildpic/aaa1.webp");
  invariants(world);

  await run();
  expect(world.rows.every((row) => row.blob_path === null)).toBe(true);
  expect([...world.bucket]).toEqual([]);
});

test("the batch limit bounds one run and the rest waits for the next", async () => {
  const result = await run({ limit: 1 });

  expect(result.promoted).toBe(1);
  expect(world.rows.filter((row) => row.tier === "bucket")).toHaveLength(1);
  invariants(world);

  expect((await run({ limit: 1 })).promoted).toBe(1);
  expect([...world.bucket]).toEqual([]);
});
