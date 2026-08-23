import { createHash } from "node:crypto";
import { beforeEach, expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { BLOB_TIER_BASE } from "./blob";
import type { PromotionPorts } from "./promote";

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
}

interface GameRow {
  shortname: string;
  logo_path: string | null;
  banner_path: string | null;
  logo_hash: string | null;
  banner_hash: string | null;
}

const HASH = (seed: string) => createHash("sha256").update(seed).digest("hex");

/** One game whose logo sits on the staging tier, as both write paths leave it. */
function stagedLogo(shortname: string, seed = `${shortname}-logo-bytes`): Fixture {
  const path = `games/${shortname}/logo.webp`;
  return {
    row: { shortname, logo_path: path, logo_hash: HASH(seed), banner_path: null, banner_hash: null },
    staged: { [path]: seed },
  };
}

class World {
  rows: GameRow[] = [];
  blob = new Map<string, Uint8Array>();
  checkout = new Set<string>();
  served = new Set<string>();
  discarded: string[] = [];
  said: string[] = [];
  trips: string[] = [];

  constructor(fixtures: Fixture[]) {
    for (const fixture of fixtures) {
      this.rows.push(fixture.row);
      for (const [path, seed] of Object.entries(fixture.staged ?? {})) {
        this.blob.set(path, new TextEncoder().encode(seed));
      }
    }
  }

  put(path: string, seed: string) {
    this.blob.set(path, new TextEncoder().encode(seed));
  }

  trip(step: string) {
    this.trips.push(step);
  }
}

function fakeSupabase(world: World): SupabaseClient {
  const builder = () => ({
    select: () => builder(),
    eq: () => builder(),
    then: (resolve: (value: { data: GameRow[]; error: null }) => unknown) =>
      resolve({ data: world.rows.map((row) => ({ ...row })), error: null }),
  });
  return { from: builder } as unknown as SupabaseClient;
}

function fakePorts(world: World): PromotionPorts {
  return {
    read: async (url: string) => {
      world.trip("read");
      const path = url.slice(BLOB_TIER_BASE.length);
      const bytes = world.blob.get(path);
      if (!bytes) throw new Error(`no object at ${path}`);
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
      const anywhere = world.blob.has(path) || world.served.has(path);
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

test("a picture already off staging is skipped without a write or a delete", async () => {
  world.blob.clear();
  const result = await run();
  expect(result.promoted).toBe(0);
  expect(world.trips).not.toContain("write");
  expect(world.trips).not.toContain("discard");
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
        banner_path: null,
        banner_hash: null,
      },
    },
  ]);
  const images = await fetchStagedGameImages(fakeSupabase(world));
  expect(images).toEqual([]);
});
