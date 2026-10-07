import { expect, test } from "bun:test";
import { servingAfterDeploy } from "./deployWait";

/**
 * A published site that swaps to a new deploy at a known time, and a clock
 * that only moves when something sleeps.
 */
function site(alreadyServed: string[], deploying: string[], landsAt: number) {
  let clock = 0;
  const sleeps: number[] = [];

  const served = async (path: string) =>
    alreadyServed.includes(path) || (deploying.includes(path) && clock >= landsAt);

  return {
    sleeps,
    wait: {
      landed: served,
      served,
      sleep: async (ms: number) => {
        sleeps.push(ms);
        clock += ms;
      },
      now: () => clock,
      timeoutMs: 1000,
      pollMs: 100,
    },
  };
}

// The promotion run of 2026-10-07: the batch opened with an object an earlier
// run had already published, so a wait on the first path ended at once and the
// 50 this run pushed were asked for before their deploy had finished.
test("waits for the deploy when the batch opens with a path that is already served", async () => {
  const world = site(["old"], ["new"], 300);

  const live = await servingAfterDeploy(["old", "new"], new Set(["new"]), world.wait);

  expect(live).toEqual(["old", "new"]);
  expect(world.sleeps).toEqual([100, 100, 100]);
});

test("does not wait when this run committed none of the paths", async () => {
  const world = site(["old"], [], 0);

  const live = await servingAfterDeploy(["old", "absent"], new Set(), world.wait);

  expect(live).toEqual(["old"]);
  expect(world.sleeps).toEqual([]);
});

test("stops waiting at the timeout and reports what is served", async () => {
  const world = site(["old"], ["new"], Infinity);

  const live = await servingAfterDeploy(["old", "new"], new Set(["new"]), world.wait);

  expect(live).toEqual(["old"]);
  expect(world.sleeps.length).toBe(10);
});

test("an empty list asks nothing", async () => {
  const world = site([], [], 0);

  expect(await servingAfterDeploy([], new Set(["new"]), world.wait)).toEqual([]);
  expect(world.sleeps).toEqual([]);
});
