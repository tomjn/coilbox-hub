import { beforeEach, expect, test } from "bun:test";
import { publishSeedBatches, seedBatches, type SeedObject, type SeedPorts } from "./seedBatch";

/**
 * A checkout, a published site and a record of what was asked of them.
 *
 * `deploys` is the switch every interrupted run in this file is built out of.
 * Turning it off is a push that lands in the repository and a Pages deploy that
 * does not, which is the failure the seed exists to survive rather than a
 * hypothetical one: it is what a deploy timeout looks like from here.
 */
class World {
  /** Paths written into the checkout and not yet committed. */
  staged = new Set<string>();
  /** Paths the assets repo holds. */
  committed = new Set<string>();
  /** Paths the durable tier answers for. */
  served = new Set<string>();
  /** Every port call, in order, which is what the batch-at-a-time claim rests
   *  on. */
  trail: string[] = [];
  said: string[] = [];
  deploys = true;

  held(path: string): boolean {
    return this.staged.has(path) || this.committed.has(path);
  }
}

function fakePorts(world: World): SeedPorts {
  return {
    held: async (to: string) => world.held(to),

    copy: async (from: string, to: string) => {
      world.trail.push(`copy ${to}`);
      expect(from).not.toBe("");
      world.staged.add(to);
    },

    publish: async () => {
      world.trail.push("publish");
      if (world.staged.size === 0) return false;

      for (const path of world.staged) world.committed.add(path);
      world.staged.clear();

      // Pages swaps the whole site at once, so a deploy publishes everything
      // committed and not merely the batch that triggered it. That is what
      // heals a batch whose own deploy failed.
      if (world.deploys) for (const path of world.committed) world.served.add(path);
      return true;
    },

    serving: async (paths: string[]) => {
      world.trail.push("serving");
      return paths.filter((path) => world.served.has(path));
    },

    say: (message: string) => {
      world.said.push(message);
    },
  };
}

/** `batch-000N/<sha>.webp` in the export, and the content addressed path it
 *  lands on in the assets repo. The two differ, which is why an object carries
 *  both. */
function object(batch: number, name: string): SeedObject {
  return {
    batch,
    from: `batch-000${batch}/${name}.webp`,
    to: `units/BYAR/buildpic/${name}.webp`,
  };
}

const CORPUS = [
  object(1, "aaa1"),
  object(1, "bbb2"),
  object(2, "ccc3"),
  // Walk order, not batch order. coilbox emits rows as it finds them, so a row
  // this late naming batch 1 is ordinary rather than a fault.
  object(1, "ddd4"),
];

let world: World;

beforeEach(() => {
  world = new World();
});

const run = () => publishSeedBatches(CORPUS, fakePorts(world));

test("objects are grouped by their batch number and not by where they sit in the list", () => {
  expect(seedBatches(CORPUS)).toEqual([
    { batch: 1, objects: [CORPUS[0], CORPUS[1], CORPUS[3]] },
    { batch: 2, objects: [CORPUS[2]] },
  ]);
});

test("batches come back lowest first however the list was ordered", () => {
  const shuffled = [object(9, "i"), object(2, "b"), object(30, "c"), object(1, "a")];

  expect(seedBatches(shuffled).map((batch) => batch.batch)).toEqual([1, 2, 9, 30]);
});

/**
 * Two rows can be the same picture. The path is content addressed so they are
 * the same bytes, and the second is not a conflict. It is just nothing to do,
 * and counting it would make the report claim work that never happened.
 */
test("two objects bound for the same path are one object", () => {
  const twice = [object(1, "aaa1"), object(1, "aaa1")];

  expect(seedBatches(twice)).toEqual([{ batch: 1, objects: [twice[0]] }]);
});

test("nothing to publish asks nothing of the checkout or the site", async () => {
  const result = await publishSeedBatches([], fakePorts(world));

  expect(result).toEqual({ published: 0, alreadyPublished: 0, copied: 0 });
  expect(world.trail).toEqual([]);
});

test("a whole corpus is published and every file ends up served", async () => {
  const result = await run();

  expect(result).toEqual({ published: 2, alreadyPublished: 0, copied: 4 });
  expect([...world.served].sort()).toEqual(CORPUS.map((object) => object.to).sort());
});

/**
 * The whole issue in one assertion. One push per batch, and the next batch is
 * not copied until the one before it is being served, so the deploys are spread
 * out rather than overlapped.
 */
test("each batch is pushed on its own and waits to be served before the next starts", async () => {
  await run();

  expect(world.trail).toEqual([
    "copy units/BYAR/buildpic/aaa1.webp",
    "copy units/BYAR/buildpic/bbb2.webp",
    "copy units/BYAR/buildpic/ddd4.webp",
    "publish",
    "serving",
    "copy units/BYAR/buildpic/ccc3.webp",
    "publish",
    "serving",
  ]);
});

/**
 * Resuming, and it rests on nothing being recorded anywhere. The second run
 * finds every path already in the repository, stages nothing, and the empty
 * commit is what tells it so.
 */
test("a second run over a published corpus copies nothing and pushes nothing", async () => {
  await run();
  world.trail = [];

  const again = await run();

  expect(again).toEqual({ published: 0, alreadyPublished: 2, copied: 0 });
  expect(world.trail).toEqual(["publish", "serving", "publish", "serving"]);
  expect(world.said).toContain("Batch 1 was already published.");
});

/**
 * A run killed between the copy and the push leaves files in the working tree
 * of a checkout, which is the cheapest thing to lose and the easiest to redo.
 * The next run finds them held, so it copies nothing, and the push it makes is
 * the one the dead run never made.
 */
test("a run that died after copying does not copy again, and publishes what it left", async () => {
  world.staged.add(CORPUS[0].to);
  world.trail = [];

  const result = await run();

  expect(result.copied).toBe(3);
  expect(result.published).toBe(2);
  expect(world.trail.filter((step) => step.startsWith("copy"))).toEqual([
    "copy units/BYAR/buildpic/bbb2.webp",
    "copy units/BYAR/buildpic/ddd4.webp",
    "copy units/BYAR/buildpic/ccc3.webp",
  ]);
});

/**
 * The one that stops a seed reading as finished when it is not. A resumed batch
 * is confirmed against the tier exactly as hard as a fresh one, so a batch that
 * was committed while its deploy failed is caught rather than counted.
 */
test("a batch committed by a run whose deploy failed is not taken on trust", async () => {
  world.deploys = false;
  await expect(run()).rejects.toThrow(/Batch 1 is committed/);

  world.trail = [];
  await expect(run()).rejects.toThrow(/serving 0 of its 3 file\(s\)/);
  expect(world.trail).toEqual(["publish", "serving"]);
});

test("a tier that will not serve a batch stops the run before the next batch is pushed", async () => {
  world.deploys = false;

  await expect(run()).rejects.toThrow(/Nothing after it has been pushed/);
  expect(world.trail.filter((step) => step === "publish")).toEqual(["publish"]);
  expect(world.committed.has(CORPUS[2].to)).toBe(false);
});

/**
 * What the operator does about it, and that the run then carries on. Re-running
 * the Pages workflow is the manual step the error asks for, and it is manual
 * because the files are already committed: there is nothing left to push, and a
 * push is what asks for a deploy.
 */
test("a batch stays stuck until the site is deployed, then the run walks past it", async () => {
  world.deploys = false;
  await expect(run()).rejects.toThrow();

  // Re-running the Pages workflow by hand, which deploys what is committed.
  world.deploys = true;
  for (const path of world.committed) world.served.add(path);

  const result = await run();

  expect(result).toEqual({ published: 1, alreadyPublished: 1, copied: 1 });
  expect([...world.served].sort()).toEqual(CORPUS.map((object) => object.to).sort());
});
