import { expect, mock, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The meters (issue #113).
 *
 * The claim worth testing is honesty rather than arithmetic: a meter nothing can
 * measure has to come back with no figure and never with a zero, and it must not
 * be able to raise or clear an alert. A dashboard-only meter reading 0% would be
 * read as headroom, which is the one wrong answer here.
 *
 * The second claim is that reading the meters is free. Asking the store how full
 * it is would be an advanced operation spent on watching the advanced operation
 * count, so every number comes out of Postgres, and the mock below makes that
 * structural rather than something to check by reading.
 */
mock.module("@vercel/blob", () => ({
  put: () => {
    throw new Error("reading the meters must never spend an advanced operation");
  },
  del: () => {
    throw new Error("reading the meters must never call the store");
  },
}));

const {
  assetClass,
  BLOB_STORAGE_ALLOWANCE_BYTES,
  durableClasses,
  fetchMeters,
  formatBytes,
  headroom,
  headroomAlerts,
} = await import("./meters");

type Meter = Awaited<ReturnType<typeof fetchMeters>>["meters"][number];

const NOW = new Date("2026-08-14T12:00:00.000Z");

interface Usage {
  tier: string;
  variant: string;
  objects: number;
  bytes: number;
}

/** Enough of PostgREST for one call each to two counts and one function. */
function fakeSupabase(usage: Usage[], counts: Record<string, number | null>): SupabaseClient {
  const table = (name: string) => {
    const builder = {
      select: () => builder,
      eq: () => builder,
      not: () => builder,
      gte: () => builder,
      then: (resolve: (value: { count: number | null; error: unknown }) => unknown) => {
        const count = counts[name];
        return resolve(
          count === null ? { count: null, error: { message: "no" } } : { count, error: null },
        );
      },
    };
    return builder;
  };

  return {
    from: table,
    rpc: () => Promise.resolve({ data: usage, error: null }),
  } as unknown as SupabaseClient;
}

function meter(report: { meters: Meter[] }, name: string): Meter {
  const found = report.meters.find((candidate) => candidate.name.startsWith(name));
  if (!found) throw new Error(`no meter called ${name}`);
  return found;
}

test("a render angle is not a class of its own", () => {
  expect(assetClass("render:270")).toBe("render");
  expect(assetClass("render:0")).toBe("render");
  expect(assetClass("buildpic")).toBe("buildpic");
  expect(assetClass("overlay:height")).toBe("overlay:height");
});

test("the durable tier is broken down by class, biggest first", () => {
  expect(
    durableClasses([
      { tier: "static", variant: "render:0", objects: 2, bytes: 200 },
      { tier: "static", variant: "render:90", objects: 1, bytes: 100 },
      { tier: "static", variant: "buildpic", objects: 9, bytes: 90 },
      { tier: "blob", variant: "buildpic", objects: 4, bytes: 4000 },
    ]),
  ).toEqual([
    { name: "render", objects: 3, bytes: 300 },
    { name: "buildpic", objects: 9, bytes: 90 },
  ]);
});

test("what the store holds is staging plus everything waiting to be swept", async () => {
  const report = await fetchMeters(
    fakeSupabase(
      [
        { tier: "blob", variant: "buildpic", objects: 2, bytes: 8192 },
        { tier: "static", variant: "minimap", objects: 5, bytes: 200_000 },
        { tier: "orphan", variant: "superseded", objects: 1, bytes: 4096 },
      ],
      { asset: 7, asset_orphan: 2 },
    ),
    NOW,
  );

  expect(meter(report, "Blob storage").used).toBe(8192 + 4096);
  expect(meter(report, "Blob storage").allowance).toBe(BLOB_STORAGE_ALLOWANCE_BYTES);
  expect(meter(report, "Durable tier").used).toBe(200_000);
  expect(report.durable).toEqual([{ name: "minimap", objects: 5, bytes: 200_000 }]);
});

test("an advanced operation is an upload with a row plus one that lost its row", async () => {
  const report = await fetchMeters(fakeSupabase([], { asset: 1200, asset_orphan: 3 }), NOW);

  expect(meter(report, "Blob advanced operations").used).toBe(1203);
  expect(meter(report, "Blob advanced operations").basis).toBe("counted");
});

test("a count that could not be read is not a count of zero", async () => {
  const report = await fetchMeters(fakeSupabase([], { asset: null, asset_orphan: 3 }), NOW);

  expect(meter(report, "Blob advanced operations").used).toBeNull();
});

test("the two meters nothing here can see say so instead of showing a zero", async () => {
  const report = await fetchMeters(fakeSupabase([], { asset: 0, asset_orphan: 0 }), NOW);

  for (const name of ["Blob data transfer", "Vercel fast data transfer", "GitHub Pages bandwidth"]) {
    expect({ name, basis: meter(report, name).basis, used: meter(report, name).used }).toEqual({
      name,
      basis: "dashboard",
      used: null,
    });
  }
});

test("nothing measurable is nothing to alert on, and nothing to reassure with either", () => {
  expect(
    headroom({
      name: "x",
      basis: "dashboard",
      used: null,
      allowance: 100,
      unit: "bytes",
      note: "",
    }),
  ).toBeNull();

  expect(
    headroomAlerts({
      at: NOW.toISOString(),
      durable: [],
      meters: [
        { name: "unmeasured", basis: "dashboard", used: null, allowance: 100, unit: "bytes", note: "" },
        { name: "roomy", basis: "counted", used: 10, allowance: 100, unit: "operations", note: "" },
      ],
    }),
  ).toEqual([]);
});

test("a counted meter three quarters full fails the run that reads it", () => {
  const alerts = headroomAlerts({
    at: NOW.toISOString(),
    durable: [],
    meters: [
      { name: "operations", basis: "counted", used: 1500, allowance: 2000, unit: "operations", note: "" },
      { name: "roomy", basis: "counted", used: 1499, allowance: 2000, unit: "operations", note: "" },
    ],
  });

  expect(alerts).toEqual(["operations: 1500 of 2000 operations, which is past 75% of the allowance."]);
});

test("bytes are read at a glance in the units the allowances are written in", () => {
  expect(formatBytes(512)).toBe("512 B");
  expect(formatBytes(1024)).toBe("1.0 KiB");
  expect(formatBytes(1024 * 1024 * 1024)).toBe("1.0 GiB");
});
