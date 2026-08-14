import { beforeEach, expect, mock, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { PromotionPorts } from "./promote";

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
 * The second one is the one that needs a column to hold it up. `list()` is
 * banned, so #113 finds orphans by enumerating from Postgres, and an object no
 * row names is an object nothing can ever find again.
 *
 * After each kill the run is restarted and has to converge, because a guarantee
 * that leaves the job wedged is not much of one.
 */

// Nothing here can reach the store. The module under test only uses
// `blobTierUrl`, which is string work, and this makes that structural rather
// than something to check by reading.
mock.module("@vercel/blob", () => ({
  put: () => {
    throw new Error("promote.test.ts must never spend an advanced operation");
  },
  del: () => {
    throw new Error("promote.test.ts must never call the store");
  },
}));

const { BLOB_TIER_BASE } = await import("./blob");
const { PROMOTION_AGE_DAYS, durablePath, promotionCutoff, runPromotion } =
  await import("./promote");

const NOW = new Date("2026-08-14T12:00:00.000Z");
const OLD = new Date("2026-08-01T12:00:00.000Z").toISOString();
const RECENT = new Date("2026-08-13T12:00:00.000Z").toISOString();

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
}

function unit(id: string, name: string, hash: string, over: Partial<Row> = {}): Row {
  return {
    id,
    game: "bar",
    unit_name: name,
    map_name: null,
    variant: "buildpic",
    hash,
    mime: "image/webp",
    path: `units/bar/buildpic/${hash}-Hn4vQ2rT.webp`,
    tier: "blob",
    moderation: "approved",
    rejection_kind: null,
    bytes: 4096,
    blob_path: null,
    promoted_at: null,
    updated_at: OLD,
    ...over,
  };
}

/**
 * Everything outside the process: the table, the store, the assets checkout and
 * what the published site is actually serving.
 */
class World {
  rows: Row[];
  /** Staging objects, by the pathname the store knows them as. */
  blob = new Set<string>();
  /** Written into the checkout but not pushed. A throwaway working tree. */
  checkout = new Set<string>();
  /** On the default branch of the assets repo. */
  pushed = new Set<string>();
  /** What the published site answers for. */
  served = new Set<string>();
  /** Which port or function throws the next time it is called. */
  failAt: string | null = null;
  said: string[] = [];
  /** So a test can assert nothing was deleted, rather than only that the row
   *  survived. */
  discarded: string[] = [];
  /** Off, and the run must then move nothing. */
  deploys = true;

  constructor(rows: Row[]) {
    this.rows = rows;
    for (const row of rows) {
      if (row.tier === "blob") this.blob.add(row.path);
      if (row.tier === "static") this.pushed.add(row.path);
      if (row.blob_path) this.blob.add(row.blob_path);
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
      if (row.tier !== "blob") return;
      if (row.moderation !== "approved") return;
      if (row.blob_path !== null) return;

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

  return {
    from: () => query(world.rows),
    rpc: (name: string, args: Record<string, unknown>) => {
      world.trip(name);

      const data =
        name === "promote_assets"
          ? promote(args.ids as string[], args.paths as string[])
          : clear(args.ids as string[]);

      return Promise.resolve({ data, error: null });
    },
  } as unknown as SupabaseClient;
}

function fakePorts(world: World): PromotionPorts {
  return {
    read: async (url: string) => {
      world.trip("read");
      const path = url.slice(BLOB_TIER_BASE.length);
      const row = world.rows.find((candidate) => candidate.path === path);
      if (!world.blob.has(path) || !row) throw new Error(`no object at ${path}`);
      return new Uint8Array(row.bytes);
    },
    held: async (path: string) => world.checkout.has(path) || world.pushed.has(path),
    write: async (path: string, bytes: Uint8Array) => {
      world.trip("write");
      expect(bytes.byteLength).toBeGreaterThan(0);
      world.checkout.add(path);
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
    discard: async (paths: string[]) => {
      world.trip("discard");
      for (const path of paths) {
        world.blob.delete(path);
        world.discarded.push(path);
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
    const where = row.tier === "blob" ? world.blob : world.pushed;
    expect({ id: row.id, tier: row.tier, reachable: where.has(row.path) }).toEqual({
      id: row.id,
      tier: row.tier,
      reachable: true,
    });
  }

  for (const path of world.blob) {
    const named = world.rows.some(
      (row) => (row.tier === "blob" && row.path === path) || row.blob_path === path,
    );
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

test("the cutoff is seven days back, on the row's own clock", () => {
  expect(promotionCutoff(NOW)).toBe("2026-08-07T12:00:00.000Z");
  expect(PROMOTION_AGE_DAYS).toBe(7);
});

test("the durable path is recomputed and is not the suffixed staging one", () => {
  const row = unit("00000000-0000-4000-8000-00000000000f", "armsolar", "aaa1");

  expect(durablePath(row)).toBe("units/bar/buildpic/aaa1.webp");
  expect(durablePath(row)).not.toBe(row.path);
});

test("a whole run moves the rows and empties the staging store", async () => {
  const result = await run();

  expect(result).toEqual({ drained: 0, promoted: 2, skipped: 0, deleted: 2 });
  expect([...world.pushed].sort()).toEqual([
    "units/bar/buildpic/aaa1.webp",
    "units/bar/buildpic/bbb2.webp",
  ]);
  expect([...world.blob]).toEqual([]);
  expect(world.rows.map((row) => `${row.tier} ${row.path} ${row.blob_path}`)).toEqual([
    "static units/bar/buildpic/aaa1.webp null",
    "static units/bar/buildpic/bbb2.webp null",
  ]);
  expect(world.rows.every((row) => row.promoted_at !== null)).toBe(true);
  invariants(world);
});

test("nothing approved in the last seven days moves", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", { updated_at: RECENT }),
  ]);

  expect(await run()).toEqual({ drained: 0, promoted: 0, skipped: 0, deleted: 0 });
  expect(world.rows[0].tier).toBe("blob");
  expect(world.discarded).toEqual([]);
});

// ## Killed at each step in turn
//
// The order in the module is drain, select, read, write, publish, serve, move,
// delete, clear. Each of these kills one of them, checks the invariants, then
// restarts the run and checks it converges.

const KILL_POINTS = [
  "read",
  "write",
  "publish",
  "serving",
  "promote_assets",
  "discard",
  "clear_promoted_blob_paths",
] as const;

for (const point of KILL_POINTS) {
  test(`killed at ${point}, the bytes are still in a tier and every object is still named`, async () => {
    world.failAt = point;

    await expect(run()).rejects.toThrow(`killed at ${point}`);

    invariants(world);

    // And nothing was deleted before the row that named it had moved.
    for (const path of world.discarded) {
      const owner = world.rows.find((row) => row.blob_path === path || row.path === path);
      expect({ path, movedFirst: owner === undefined || owner.tier === "static" }).toEqual({
        path,
        movedFirst: true,
      });
    }
  });

  test(`killed at ${point}, the next run finishes the job`, async () => {
    world.failAt = point;
    await expect(run()).rejects.toThrow();

    const result = await run();

    expect(world.rows.every((row) => row.tier === "static")).toBe(true);
    expect(world.rows.every((row) => row.blob_path === null)).toBe(true);
    expect([...world.blob]).toEqual([]);
    expect(result.drained + result.deleted).toBe(2);
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
  const read = ports.read;
  ports.read = async (url) => {
    const bytes = await read(url);
    world.rows[1].moderation = "rejected";
    world.rows[1].rejection_kind = "safety";
    return bytes;
  };

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result.promoted).toBe(1);
  expect(result.skipped).toBe(1);
  expect(world.rows[1].tier).toBe("blob");
  expect(world.blob.has(world.rows[1].path)).toBe(true);
  expect(world.discarded).toEqual(["units/bar/buildpic/aaa1-Hn4vQ2rT.webp"]);
  invariants(world);
});

test("a durable tier that never serves the batch moves nothing at all", async () => {
  world.deploys = false;

  await expect(run()).rejects.toThrow("is serving 0");

  expect(world.rows.every((row) => row.tier === "blob")).toBe(true);
  expect(world.discarded).toEqual([]);
  invariants(world);
});

test("a staging object left by an earlier run is drained before anything else", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      tier: "static",
      path: "units/bar/buildpic/aaa1.webp",
      blob_path: "units/bar/buildpic/aaa1-Hn4vQ2rT.webp",
      promoted_at: OLD,
    }),
  ]);

  const result = await run();

  expect(result.drained).toBe(1);
  expect([...world.blob]).toEqual([]);
  expect(world.rows[0].blob_path).toBe(null);
  invariants(world);
});

test("a leftover the durable tier is not serving is kept rather than deleted", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1", {
      tier: "static",
      path: "units/bar/buildpic/aaa1.webp",
      blob_path: "units/bar/buildpic/aaa1-Hn4vQ2rT.webp",
      promoted_at: OLD,
    }),
  ]);
  world.served.clear();

  const result = await run();

  expect(result.drained).toBe(0);
  expect(world.blob.has("units/bar/buildpic/aaa1-Hn4vQ2rT.webp")).toBe(true);
  expect(world.rows[0].blob_path).toBe("units/bar/buildpic/aaa1-Hn4vQ2rT.webp");
  expect(world.said).toContain(
    "keep units/bar/buildpic/aaa1-Hn4vQ2rT.webp: the durable tier is not serving units/bar/buildpic/aaa1.webp yet.",
  );
});

test("a leftover from a row a newer archive replaced is deleted without a gate", async () => {
  // The row went back to the staging tier, so its `path` is a staging path and
  // there is no durable copy to confirm. The queued object is simply the
  // superseded one, and only `blob_path` still names it.
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "ccc3", {
      updated_at: RECENT,
      blob_path: "units/bar/buildpic/aaa1-Hn4vQ2rT.webp",
    }),
  ]);

  const result = await run();

  expect(result.drained).toBe(1);
  expect(world.discarded).toEqual(["units/bar/buildpic/aaa1-Hn4vQ2rT.webp"]);
  expect(world.blob.has(world.rows[0].path)).toBe(true);
  invariants(world);
});

test("two rows that are the same picture share one object and both move", async () => {
  world = new World([
    unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1"),
    unit("00000000-0000-4000-8000-000000000002", "armadvsol", "aaa1", {
      path: "units/bar/buildpic/aaa1-Zx91Kp2w.webp",
    }),
  ]);

  const result = await run();

  expect(result.promoted).toBe(2);
  expect([...world.pushed]).toEqual(["units/bar/buildpic/aaa1.webp"]);
  expect(world.discarded.sort()).toEqual([
    "units/bar/buildpic/aaa1-Hn4vQ2rT.webp",
    "units/bar/buildpic/aaa1-Zx91Kp2w.webp",
  ]);
  expect([...world.blob]).toEqual([]);
});

test("bytes that are not the length the row claims are never committed", async () => {
  world = new World([unit("00000000-0000-4000-8000-000000000001", "armsolar", "aaa1")]);

  const ports = fakePorts(world);
  ports.read = async () => new Uint8Array(11);

  const result = await runPromotion(fakeSupabase(world), ports, { now: NOW });

  expect(result).toEqual({ drained: 0, promoted: 0, skipped: 1, deleted: 0 });
  expect([...world.pushed]).toEqual([]);
  expect(world.rows[0].tier).toBe("blob");
  expect(world.said[0]).toContain("the store returned 11 bytes and the row says 4096");
});

test("the batch limit bounds one run and the rest waits for the next", async () => {
  const result = await run({ limit: 1 });

  expect(result.promoted).toBe(1);
  expect(world.rows.filter((row) => row.tier === "blob")).toHaveLength(1);
  invariants(world);

  expect((await run({ limit: 1 })).promoted).toBe(1);
  expect([...world.blob]).toEqual([]);
});
