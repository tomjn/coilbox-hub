import { createHash } from "node:crypto";
import { beforeEach, expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { BLOB_TIER_BASE } from "./blob";
import { type PromotionPorts, StagingReadError } from "./promote";

const { runGameImagePromotion, fetchStagedGameImages } = await import("./promoteGameImages");

/**
 * The game picture half of promotion (#285), and the same claim in a smaller
 * room: an interrupted run leaves a picture in both tiers and never in neither.
 * The path is deterministic, so unlike the asset run there is no suffix to
 * recompute and no `blob_path` to hold up - which makes the row's hash the only
 * witness for whether staging bytes are the ones asked for.
 */

/** A staging object is the seed's bytes; a row hash is the seed's hash. The two
 *  halves of one fixture have to agree, which is what this type carries. */
interface Fixture {
  row: GameRow;
  /** Staging objects, pathname to seed. Absent means the picture is not on the
   *  staging tier at all. */
  staged?: Record<string, string>;
  /** The staged objects are in the bucket rather than Blob. */
  inBucket?: boolean;
}

interface GameRow {
  shortname: string;
  logo_path: string | null;
  banner_path: string | null;
  logo_hash: string | null;
  banner_hash: string | null;
  logo_staged_tier: string | null;
  banner_staged_tier: string | null;
}

const HASH = (seed: string) => createHash("sha256").update(seed).digest("hex");

/** One game whose logo sits on the staging tier, as both write paths leave it. */
function stagedLogo(shortname: string, seed = `${shortname}-logo-bytes`): Fixture {
  const path = `games/${shortname}/logo.webp`;
  return {
    row: {
      shortname,
      logo_path: path,
      logo_hash: HASH(seed),
      logo_staged_tier: "blob",
      banner_path: null,
      banner_hash: null,
      banner_staged_tier: null,
    },
    staged: { [path]: seed },
  };
}

/** The same, uploaded since #332, so the staged copy is in the bucket. */
function bucketLogo(shortname: string, seed = `${shortname}-logo-bytes`): Fixture {
  const fixture = stagedLogo(shortname, seed);
  fixture.row.logo_staged_tier = "bucket";
  fixture.inBucket = true;
  return fixture;
}

class World {
  rows: GameRow[] = [];
  blob = new Map<string, Uint8Array>();
  bucket = new Map<string, Uint8Array>();
  reservations = new Set<string>();
  discardedStaged: string[] = [];
  /** Which step throws the next time it is reached. */
  failAt: string | null = null;
  checkout = new Set<string>();
  served = new Set<string>();
  discarded: string[] = [];
  said: string[] = [];
  trips: string[] = [];

  constructor(fixtures: Fixture[]) {
    for (const fixture of fixtures) {
      this.rows.push(fixture.row);
      for (const [path, seed] of Object.entries(fixture.staged ?? {})) {
        (fixture.inBucket ? this.bucket : this.blob).set(path, new TextEncoder().encode(seed));
      }
    }
  }

  put(path: string, seed: string) {
    this.blob.set(path, new TextEncoder().encode(seed));
  }

  trip(step: string) {
    this.trips.push(step);
    if (this.failAt === step) {
      this.failAt = null;
      throw new Error(`killed at ${step}`);
    }
  }

  /** Whether a game row claims a bucket path, as 20260914170000 decides it. */
  claims(path: string) {
    return this.rows.some(
      (row) =>
        (row.logo_path === path && row.logo_staged_tier === "bucket") ||
        (row.banner_path === path && row.banner_staged_tier === "bucket"),
    );
  }
}

function fakeSupabase(world: World): SupabaseClient {
  const builder = (update?: Partial<GameRow>) => {
    const filters: [keyof GameRow, unknown][] = [];
    const chain = {
      select: () => chain,
      update: (values: Partial<GameRow>) => builder(values),
      eq: (column: keyof GameRow, value: unknown) => {
        filters.push([column, value]);
        return chain;
      },
      then: (resolve: (value: { data: GameRow[]; error: null }) => unknown) => {
        const matching = world.rows.filter((row) => filters.every(([column, value]) => row[column] === value));
        if (update) {
          world.trip("clear");
          for (const row of matching) Object.assign(row, update);
        }
        return resolve({ data: matching.map((row) => ({ ...row })), error: null });
      },
    };
    return chain;
  };

  const rpc = (name: string, args: Record<string, unknown>) => {
    world.trip(name);
    if (name === "reserve_staged_deletions") {
      const data = (args.object_paths as string[])
        .filter((path) => !world.claims(path))
        .map((path) => {
          world.reservations.add(path);
          return { object_path: path };
        });
      return Promise.resolve({ data, error: null });
    }
    const released = (args.object_paths as string[]).filter((path) => world.reservations.delete(path));
    return Promise.resolve({ data: released.length, error: null });
  };

  return { from: () => builder(), rpc } as unknown as SupabaseClient;
}

function fakePorts(world: World): PromotionPorts {
  return {
    read: async (url: string) => {
      world.trip("read");
      const path = url.slice(BLOB_TIER_BASE.length);
      const bytes = world.blob.get(path);
      if (!bytes) throw new StagingReadError(404, url);
      return bytes;
    },
    readStaged: async (path: string) => {
      world.trip("readStaged");
      const bytes = world.bucket.get(path);
      if (!bytes) throw new StagingReadError(404, path);
      return bytes;
    },
    held: async (path) => world.checkout.has(path),
    write: async (path) => {
      world.trip("write");
      world.checkout.add(path);
    },
    publish: async (paths) => {
      world.trip("publish");
      expect(paths.length).toBeGreaterThan(0);
      for (const path of paths) world.served.add(path);
    },
    serving: async (paths) => {
      world.trip("serving");
      return paths.filter((path) => world.served.has(path));
    },
    discard: async (paths) => {
      world.trip("discard");
      for (const path of paths) {
        world.blob.delete(path);
        world.discarded.push(path);
      }
    },
    discardStaged: async (paths) => {
      world.trip("discardStaged");
      for (const path of paths) {
        world.bucket.delete(path);
        world.discardedStaged.push(path);
      }
    },
    say: (message) => world.said.push(message),
  };
}

/** What must be true of the world at every instant: a row's bytes are reachable
 *  on at least one tier. Neither tier alone is required - both is the safe
 *  direction an interrupted run leaves things in. */
function reachable(world: World) {
  for (const row of world.rows) {
    for (const path of [row.logo_path, row.banner_path]) {
      if (!path) continue;
      const anywhere = world.blob.has(path) || world.bucket.has(path) || world.served.has(path);
      expect({ path, anywhere }).toEqual({ path, anywhere: true });
    }
  }
}

let world: World;

beforeEach(() => {
  world = new World([stagedLogo("SF"), stagedLogo("BA")]);
});

const run = () => runGameImagePromotion(fakeSupabase(world), fakePorts(world));

test("a staged picture is written, published, confirmed serving, then cleared from staging", async () => {
  const result = await run();

  expect(result.promoted).toBe(2);
  expect(result.skipped).toBe(0);
  expect(world.served.has("games/SF/logo.webp")).toBe(true);
  expect(world.blob.size).toBe(0);
  reachable(world);
});

test("the run happens in the order that never loses bytes", async () => {
  await run();
  // Read before write, write before publish, publish before serving is asked,
  // and discard only after all of it.
  const order = world.trips.join(",");
  expect(order.startsWith("read,")).toBe(true);
  expect(order.indexOf("write")).toBeGreaterThan(-1);
  expect(order.indexOf("write")).toBeLessThan(order.indexOf("publish"));
  expect(order.indexOf("publish")).toBeLessThan(order.indexOf("serving"));
  expect(order.lastIndexOf("serving")).toBeLessThan(order.indexOf("discard"));
});

test("a picture already off staging is skipped without a write or a delete, and stops saying it is staged", async () => {
  world.blob.clear();
  const result = await run();
  expect(result.promoted).toBe(0);
  expect(world.trips).not.toContain("write");
  expect(world.trips).not.toContain("discard");
  // Nothing writes to Blob any more, so the absence is permanent (#335).
  expect(world.rows.map((row) => row.logo_staged_tier)).toEqual([null, null]);
});

test("a promoted Blob picture is deleted first and its staged tier cleared after", async () => {
  await run();

  const order = world.trips.join(",");
  expect(order.lastIndexOf("discard")).toBeLessThan(order.lastIndexOf("clear"));
  expect(world.rows.map((row) => row.logo_staged_tier)).toEqual([null, null]);
});

test("a store that refuses a read is said out loud rather than read as already promoted", async () => {
  const ports = fakePorts(world);
  ports.read = async (url) => {
    throw new StagingReadError(403, url);
  };

  const result = await runGameImagePromotion(fakeSupabase(world), ports);

  expect(result).toEqual({ promoted: 0, skipped: 2, unreadable: 2 });
  expect(world.said.join("\n")).toContain("403 reading");
  expect(world.discarded).toEqual([]);
  reachable(world);
});

test("staging bytes the row does not name are left alone and said out loud", async () => {
  world.put("games/SF/logo.webp", "somebody else's bytes");
  const result = await run();

  // SF's logo skipped; BA's own still moved.
  expect(result.promoted).toBe(1);
  expect(result.skipped).toBe(1);
  expect(world.said.join("\n")).toContain("does not name");
  expect(world.served.has("games/SF/logo.webp")).toBe(false);
  expect(world.blob.get("games/SF/logo.webp")).toBeDefined();
  reachable(world);
});

test("a push the durable tier never serves throws before anything is deleted", async () => {
  const ports = fakePorts(world);
  ports.publish = async (paths) => {
    world.trip("publish");
    void paths;
    // The deploy did not happen, so nothing lands in `served`.
  };
  await expect(runGameImagePromotion(fakeSupabase(world), ports)).rejects.toThrow(
    /Nothing has been deleted/,
  );
  expect(world.blob.size).toBe(2);
  reachable(world);
});

test("a re-run after an interrupted one converges", async () => {
  // Kill the first run after its push by having the serving check come back
  // empty once, then run again cleanly.
  const ports = fakePorts(world);
  const realServing = ports.serving;
  let attempts = 0;
  ports.serving = async (paths) => {
    attempts++;
    return attempts === 1 ? [] : realServing(paths);
  };
  await expect(runGameImagePromotion(fakeSupabase(world), ports)).rejects.toThrow();
  reachable(world);

  const second = await run();
  expect(second.promoted).toBe(2);
  expect(world.blob.size).toBe(0);
  reachable(world);
});

test("rows with paths but no hashes are not offered for promotion", async () => {
  world = new World([
    {
      row: {
        shortname: "EE",
        logo_path: "games/EE/logo.png",
        logo_hash: null,
        logo_staged_tier: "blob",
        banner_path: null,
        banner_hash: null,
        banner_staged_tier: null,
      },
    },
  ]);
  const images = await fetchStagedGameImages(fakeSupabase(world));
  expect(images).toEqual([]);
});

test("a picture staged in the bucket is read and deleted there and never in Blob, even when Blob holds the same bytes", async () => {
  // The worst case for reading Blob at a bucket row's path: an older upload of
  // identical bytes is still in Blob, so the hash matches, and promoting and
  // deleting that would leave the bucket copy behind for good.
  const bucket = bucketLogo("SF");
  world = new World([bucket, stagedLogo("BA")]);
  world.put("games/SF/logo.webp", "SF-logo-bytes");

  const result = await run();

  expect(result).toEqual({ promoted: 2, skipped: 0, unreadable: 0 });
  expect(world.discarded).toEqual(["games/BA/logo.webp"]);
  expect(world.discardedStaged).toEqual(["games/SF/logo.webp"]);
  expect(world.blob.has("games/SF/logo.webp")).toBe(true);
  expect(world.bucket.size).toBe(0);
  expect(world.rows.map((row) => row.logo_staged_tier)).toEqual([null, null]);
  expect(world.reservations.size).toBe(0);
  reachable(world);
});

test("a bucket picture's staged tier is cleared before its object is reserved and deleted", async () => {
  world = new World([bucketLogo("SF")]);

  await run();

  const after = world.trips.slice(world.trips.lastIndexOf("serving") + 1);
  expect(after).toEqual(["clear", "reserve_staged_deletions", "discardStaged", "release_staged_deletions"]);
});

test("an owner upload that lands while a bucket picture is promoted keeps the new picture", async () => {
  // The upload overwrites the object and moves the row to the new hash after
  // the run read the old bytes. The conditional clear finds the row changed,
  // so neither the row nor the object is touched, and the next run promotes the
  // new art.
  world = new World([bucketLogo("SF")]);
  const ports = fakePorts(world);
  const publish = ports.publish;
  ports.publish = async (paths) => {
    await publish(paths);
    world.bucket.set("games/SF/logo.webp", new TextEncoder().encode("new art"));
    world.rows[0].logo_hash = HASH("new art");
  };

  const result = await runGameImagePromotion(fakeSupabase(world), ports);

  expect(result.promoted).toBe(1);
  expect(world.discardedStaged).toEqual([]);
  expect(world.rows[0].logo_staged_tier).toBe("bucket");
  expect(world.said).toContain("keep games/SF/logo.webp: a newer upload replaced it after it was read.");

  const next = await run();
  expect(next.promoted).toBe(1);
  expect(world.discardedStaged).toEqual(["games/SF/logo.webp"]);
  expect(world.rows[0].logo_staged_tier).toBe(null);
});

test("a run killed between clearing a bucket picture and deleting it leaves an object the sweep can find", async () => {
  world = new World([bucketLogo("SF")]);
  world.failAt = "reserve_staged_deletions";

  await expect(run()).rejects.toThrow("killed at reserve_staged_deletions");

  // Served on the durable tier, still in the bucket, and claimed by nothing,
  // which is exactly what `unclaimed_staged_objects` lists.
  expect(world.served.has("games/SF/logo.webp")).toBe(true);
  expect(world.bucket.has("games/SF/logo.webp")).toBe(true);
  expect(world.claims("games/SF/logo.webp")).toBe(false);
  reachable(world);
});

test("a row naming a bucket copy that is not there is said out loud and left as it is", async () => {
  world = new World([bucketLogo("SF")]);
  world.bucket.clear();

  const result = await run();

  expect(result).toEqual({ promoted: 0, skipped: 1, unreadable: 0 });
  expect(world.said).toContain("skip games/SF/logo.webp: the row says the bucket holds it and nothing is there.");
  expect(world.rows[0].logo_staged_tier).toBe("bucket");
  expect(world.discardedStaged).toEqual([]);
});

test("a row that records no staged copy is not offered for promotion", async () => {
  const imported = stagedLogo("EE");
  imported.row.logo_staged_tier = null;
  world = new World([imported]);

  expect(await fetchStagedGameImages(fakeSupabase(world))).toEqual([]);
});
