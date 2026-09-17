import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The meters (issue #113).
 *
 * The claim worth testing is honesty rather than arithmetic: a meter nothing can
 * measure has to come back with no figure and never with a zero, and it must not
 * be able to raise or clear an alert. A dashboard-only meter reading 0% would be
 * read as headroom, which is the one wrong answer here.
 */
const {
  assetClass,
  durableClasses,
  fetchMeters,
  formatBytes,
  headroom,
  headroomAlerts,
  SUPABASE_STORAGE_ALLOWANCE_BYTES,
} = await import("./meters");

type Meter = Awaited<ReturnType<typeof fetchMeters>>["meters"][number];

const NOW = new Date("2026-08-14T12:00:00.000Z");

interface Usage {
  tier: string;
  variant: string;
  objects: number;
  bytes: number;
}

/** Enough of PostgREST for two functions. `bucketBytes` is what
 *  `staged_pictures_bucket_bytes` answers with, null meaning the call failed. */
function fakeSupabase(usage: Usage[], bucketBytes: number | null = 0): SupabaseClient {
  return {
    rpc: (name: string) => {
      if (name === "staged_pictures_bucket_bytes") {
        return Promise.resolve(
          bucketBytes === null
            ? { data: null, error: { message: "no" } }
            : { data: bucketBytes, error: null },
        );
      }
      return Promise.resolve({ data: usage, error: null });
    },
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
      { tier: "bucket", variant: "buildpic", objects: 4, bytes: 4000 },
    ]),
  ).toEqual([
    { name: "render", objects: 3, bytes: 300 },
    { name: "buildpic", objects: 9, bytes: 90 },
  ]);
});

test("the durable tier meter counts only static rows", async () => {
  const report = await fetchMeters(
    fakeSupabase([
      { tier: "bucket", variant: "buildpic", objects: 2, bytes: 8192 },
      { tier: "static", variant: "minimap", objects: 5, bytes: 200_000 },
      { tier: "orphan", variant: "superseded", objects: 1, bytes: 4096 },
    ]),
    NOW,
  );

  expect(meter(report, "Durable tier").used).toBe(200_000);
  expect(report.durable).toEqual([{ name: "minimap", objects: 5, bytes: 200_000 }]);
});

test("there is no Blob meter left to raise an alarm", async () => {
  const report = await fetchMeters(fakeSupabase([]), NOW);

  expect(report.meters.filter((candidate) => candidate.name.includes("Blob"))).toEqual([]);
});

test("the meters nothing here can see say so instead of showing a zero", async () => {
  const report = await fetchMeters(fakeSupabase([]), NOW);

  for (const name of [
    "Supabase egress",
    "Vercel fast data transfer",
    "GitHub Pages bandwidth",
  ]) {
    expect({ name, basis: meter(report, name).basis, used: meter(report, name).used }).toEqual({
      name,
      basis: "dashboard",
      used: null,
    });
  }
});

test("the Supabase Storage meter is the staged-pictures bucket's size", async () => {
  const report = await fetchMeters(fakeSupabase([], 123_456), NOW);

  expect(meter(report, "Supabase Storage").used).toBe(123_456);
  expect(meter(report, "Supabase Storage").basis).toBe("counted");
  expect(meter(report, "Supabase Storage").allowance).toBe(SUPABASE_STORAGE_ALLOWANCE_BYTES);
});

test("a bucket size that could not be read is not a size of zero", async () => {
  const report = await fetchMeters(fakeSupabase([], null), NOW);

  expect(meter(report, "Supabase Storage").used).toBeNull();
});

test("nothing measurable is nothing to alert on, and nothing to reassure with either", () => {
  expect(
    headroom({
      name: "x",
      basis: "dashboard",
      used: null,
      allowance: 100,
      note: "",
    }),
  ).toBeNull();

  expect(
    headroomAlerts({
      at: NOW.toISOString(),
      durable: [],
      meters: [
        { name: "unmeasured", basis: "dashboard", used: null, allowance: 100, note: "" },
        { name: "roomy", basis: "counted", used: 10, allowance: 100, note: "" },
      ],
    }),
  ).toEqual([]);
});

test("a counted meter three quarters full fails the run that reads it", () => {
  const alerts = headroomAlerts({
    at: NOW.toISOString(),
    durable: [],
    meters: [
      { name: "bucket", basis: "counted", used: 1536, allowance: 2048, note: "" },
      { name: "roomy", basis: "counted", used: 1535, allowance: 2048, note: "" },
    ],
  });

  expect(alerts).toEqual(["bucket: 1.5 KiB of 2.0 KiB, which is past 75% of the allowance."]);
});

test("bytes are read at a glance in the units the allowances are written in", () => {
  expect(formatBytes(512)).toBe("512 B");
  expect(formatBytes(1024)).toBe("1.0 KiB");
  expect(formatBytes(1024 * 1024 * 1024)).toBe("1.0 GiB");
});
