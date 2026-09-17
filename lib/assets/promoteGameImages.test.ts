import { createHash } from "node:crypto";
import { beforeEach, expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { type PromotionPorts, StagingReadError } from "./promote";

const { runGameImagePromotion, fetchStagedGameImages } = await import("./promoteGameImages");

/**
 * The game picture half of promotion (#285), and the same claim in a smaller
 * room: an interrupted run leaves a picture in both tiers and never in neither.
 * The path is deterministic, so unlike the asset run there is no suffix to
 * recompute and no `blob_path` to hold up - which makes the row's hash the only
 * witness for whether staging bytes are the ones asked for.
 */

/** A staging object is the seed's bytes, and a row hash is the seed's hash. The
 *  two halves of one fixture have to agree, which is what this type carries. */
interface Fixture {
  row: GameRow;
  /** Staging objects, pathname to seed. Absent means the picture is not on the
   *  staging tier at all. */
  staged?: Record<string, string>;
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
      logo_staged_tier: "bucket",
      banner_path: null,
      banner_hash: null,
      banner_staged_tier: null,
    },
    staged: { [path]: seed },
  };
}

class World {
  rows: GameRow[] = [];
  bucket = new Map<string, Uint8Array>();
  reservations = new Set<string>();
  discardedStaged: string[] = [];
  /** Which step throws the next time it is reached. */
  failAt: string | null = null;
  checkout = new Set<string>();
  /** Files taken out of the checkout that the next publish deletes. */
  removing: string[] = [];
  served = new Set<string>();
  said: string[] = [];
  trips: string[] = [];

  constructor(fixtures: Fixture[]) {
    for (const fixture of fixtures) {
      this.rows.push(fixture.row);
      for (const [path, seed] of Object.entries(fixture.staged ?? {})) {
        this.bucket.set(path, new TextEncoder().encode(seed));
      }
    }
  }

  put(path: string, seed: string) {
    this.bucket.set(path, new TextEncoder().encode(seed));
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
    remove: async (path) => {
      world.trip("remove");
      world.checkout.delete(path);
      world.removing.push(path);
    },
    publish: async (paths) => {
      world.trip("publish");
      expect(paths.length + world.removing.length).toBeGreaterThan(0);
      for (const path of paths) world.served.add(path);
      for (const path of world.removing) world.served.delete(path);
      world.removing = [];
    },
    serving: async (paths) => {
      world.trip("serving");
      return paths.filter((path) => world.served.has(path));
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
      const anywhere = world.bucket.has(path) || world.served.has(path);
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
  expect(world.bucket.size).toBe(0);
  reachable(world);
});

test("the run happens in the order that never loses bytes", async () => {
  await run();
  // Read before write, write before publish, publish before serving is asked,
  // and the delete only after all of it.
  const order = world.trips.join(",");
  expect(order.startsWith("readStaged,")).toBe(true);
  expect(order.indexOf("write")).toBeGreaterThan(-1);
  expect(order.indexOf("write")).toBeLessThan(order.indexOf("publish"));
  expect(order.indexOf("publish")).toBeLessThan(order.indexOf("serving"));
  expect(order.lastIndexOf("serving")).toBeLessThan(order.indexOf("discardStaged"));
});

test("a picture already off staging is skipped without a write or a delete, and stops saying it is staged", async () => {
  world.bucket.clear();
  const result = await run();
  expect(result.promoted).toBe(0);
  expect(world.trips).not.toContain("write");
  expect(world.trips).not.toContain("discardStaged");
  expect(world.rows.map((row) => row.logo_staged_tier)).toEqual(["bucket", "bucket"]);
});

test("a store that refuses a read is said out loud rather than read as already promoted", async () => {
  const ports = fakePorts(world);
  ports.readStaged = async (path) => {
    throw new StagingReadError(403, path);
  };

  const result = await runGameImagePromotion(fakeSupabase(world), ports);

  expect(result).toEqual({ promoted: 0, skipped: 2, unreadable: 2, removed: 0 });
  expect(world.said.join("\n")).toContain("403 reading");
  expect(world.discardedStaged).toEqual([]);
  reachable(world);
});

test("staging bytes the row does not name are left alone and said out loud", async () => {
  world.put("games/SF/logo.webp", "somebody else's bytes");
  const result = await run();

  // SF's logo is skipped, and BA's own still moves.
  expect(result.promoted).toBe(1);
  expect(result.skipped).toBe(1);
  expect(world.said.join("\n")).toContain("does not name");
  expect(world.served.has("games/SF/logo.webp")).toBe(false);
  expect(world.bucket.get("games/SF/logo.webp")).toBeDefined();
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
  expect(world.bucket.size).toBe(2);
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
  expect(world.bucket.size).toBe(0);
  reachable(world);
});

test("rows with paths but no hashes are not offered for promotion", async () => {
  world = new World([
    {
      row: {
        shortname: "EE",
        logo_path: "games/EE/logo.png",
        logo_hash: null,
        logo_staged_tier: "bucket",
        banner_path: null,
        banner_hash: null,
        banner_staged_tier: null,
      },
    },
  ]);
  const images = await fetchStagedGameImages(fakeSupabase(world));
  expect(images).toEqual([]);
});

test("a bucket picture's staged tier is cleared before its object is reserved and deleted", async () => {
  world = new World([stagedLogo("SF")]);

  await run();

  const after = world.trips.slice(world.trips.lastIndexOf("serving") + 1);
  expect(after).toEqual(["clear", "reserve_staged_deletions", "discardStaged", "release_staged_deletions"]);
});

test("an owner upload that lands while a bucket picture is promoted keeps the new picture", async () => {
  // The upload overwrites the object and moves the row to the new hash after
  // the run read the old bytes. The conditional clear finds the row changed,
  // so neither the row nor the object is touched, and the next run promotes the
  // new art.
  world = new World([stagedLogo("SF")]);
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
  expect(world.said).toContain("keep games/SF/logo.webp: a newer upload or a removal changed the row after it was read.");

  const next = await run();
  expect(next.promoted).toBe(1);
  expect(world.discardedStaged).toEqual(["games/SF/logo.webp"]);
  expect(world.rows[0].logo_staged_tier).toBe(null);
});

test("a run killed between clearing a bucket picture and deleting it leaves an object the sweep can find", async () => {
  world = new World([stagedLogo("SF")]);
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
  world = new World([stagedLogo("SF")]);
  world.bucket.clear();

  const result = await run();

  expect(result).toEqual({ promoted: 0, skipped: 1, unreadable: 0, removed: 0 });
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

// ## Removed art (#360)
//
// Removing a logo or banner only clears its row. These prove promotion never
// pushes art whose row stopped naming it before the rows were read again, and
// deletes from the durable tier whatever no row names.

/** A row whose logo an owner or moderator has removed: every column cleared. */
function removedLogo(row: GameRow) {
  row.logo_path = null;
  row.logo_hash = null;
  row.logo_staged_tier = null;
}

/** A logo promoted by an earlier run: on the durable tier, no staged copy. */
function promotedLogo(shortname: string): Fixture {
  const fixture = stagedLogo(shortname);
  fixture.row.logo_staged_tier = null;
  fixture.staged = {};
  return fixture;
}

test("a logo removed after it was promoted is deleted from the durable tier, with no gate and no staging touched", async () => {
  world = new World([promotedLogo("BA")]);
  world.checkout.add("games/BA/logo.webp");
  world.served.add("games/BA/logo.webp");
  removedLogo(world.rows[0]);

  const result = await run();

  expect(result).toEqual({ promoted: 0, skipped: 0, unreadable: 0, removed: 1 });
  expect(world.trips).toEqual(["remove", "publish"]);
  expect(world.served.has("games/BA/logo.webp")).toBe(false);
  expect(world.said).toContain("remove games/BA/logo.webp: no game row names it.");
});

test("a logo removed before promotion is never written or pushed, and its bucket object is left for the sweep", async () => {
  world = new World([stagedLogo("BA")]);
  removedLogo(world.rows[0]);

  const result = await run();

  expect(result).toEqual({ promoted: 0, skipped: 0, unreadable: 0, removed: 0 });
  expect(world.trips).not.toContain("write");
  expect(world.trips).not.toContain("publish");
  expect(world.served.size).toBe(0);
  // Unclaimed, which is what `unclaimed_staged_objects` lists.
  expect(world.bucket.has("games/BA/logo.webp")).toBe(true);
  expect(world.claims("games/BA/logo.webp")).toBe(false);
});

test("a logo removed while the run reads its bytes is taken back out of the checkout and never pushed", async () => {
  world = new World([stagedLogo("BA")]);
  const ports = fakePorts(world);
  const readStaged = ports.readStaged;
  ports.readStaged = async (path) => {
    const bytes = await readStaged(path);
    removedLogo(world.rows[0]);
    return bytes;
  };

  const result = await runGameImagePromotion(fakeSupabase(world), ports);

  expect(result).toEqual({ promoted: 0, skipped: 1, unreadable: 0, removed: 1 });
  expect(world.checkout.has("games/BA/logo.webp")).toBe(false);
  expect(world.served.has("games/BA/logo.webp")).toBe(false);
  expect(world.trips).not.toContain("serving");
  expect(world.discardedStaged).toEqual([]);
  expect(world.said).toContain("skip games/BA/logo.webp: its row stopped naming it after it was read.");
});

test("a logo removed after the rows were read again is pushed once, left unclaimed in the bucket, and deleted by the next run", async () => {
  world = new World([stagedLogo("BA")]);
  const ports = fakePorts(world);
  const publish = ports.publish;
  ports.publish = async (paths) => {
    await publish(paths);
    removedLogo(world.rows[0]);
  };

  const first = await runGameImagePromotion(fakeSupabase(world), ports);

  expect(first).toEqual({ promoted: 1, skipped: 0, unreadable: 0, removed: 0 });
  expect(world.said).toContain(
    "keep games/BA/logo.webp: a newer upload or a removal changed the row after it was read.",
  );
  expect(world.claims("games/BA/logo.webp")).toBe(false);
  expect(world.bucket.has("games/BA/logo.webp")).toBe(true);

  const next = await run();

  expect(next).toEqual({ promoted: 0, skipped: 0, unreadable: 0, removed: 1 });
  expect(world.served.has("games/BA/logo.webp")).toBe(false);
});

test("a promoted picture the row still names is kept, and one at an old extension goes out in the same push as the new one", async () => {
  const replaced = stagedLogo("BA");
  world = new World([replaced, promotedLogo("SF")]);
  for (const path of ["games/BA/logo.png", "games/SF/logo.webp"]) {
    world.checkout.add(path);
    world.served.add(path);
  }

  const result = await run();

  expect(result).toEqual({ promoted: 1, skipped: 0, unreadable: 0, removed: 1 });
  expect(world.trips.filter((trip) => trip === "publish")).toHaveLength(1);
  expect([...world.served].sort()).toEqual(["games/BA/logo.webp", "games/SF/logo.webp"]);
});
