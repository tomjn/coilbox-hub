import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapEntry } from "./facts";
import { buildSubmission, type MapSubmission, submitMapFacts } from "./submit";

const USER = "11111111-1111-1111-1111-111111111111";

function entry(mapName: string): MapEntry {
  return {
    map_name: mapName,
    display_name: null,
    description: null,
    map_version: null,
    author: null,
    archive_filename: null,
    source_archive: "comet_catcher_remake_1.8.sd7",
    source_hash: "src-comet",
    catalog_version: 3,
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -120.5,
    world_height_max: 890,
    min_wind: null,
    max_wind: null,
    tidal_strength: null,
    void_water: null,
    void_ground: null,
    water_coverage: null,
    appearance: {},
    points: { start: [], metal: [], geo: [] },
  };
}

interface Call {
  name: string;
  args: Record<string, unknown>;
}

/**
 * A stand in for the secret key client. What is under test here is the one call
 * the route makes and what it does with the answer, so nothing touches Postgres:
 * the rules behind the function are proved in
 * `supabase/tests/map_submission.test.sql`, where they can be run against real
 * rows.
 */
function fakeSupabase(
  answer: { data: unknown; error: { code?: string } | null },
  calls: Call[] = [],
): SupabaseClient {
  return {
    rpc: (name: string, args: Record<string, unknown>) => {
      calls.push({ name, args });
      return Promise.resolve(answer);
    },
  } as unknown as SupabaseClient;
}

async function submissions(...names: string[]): Promise<MapSubmission[]> {
  return Promise.all(names.map((name) => buildSubmission(entry(name))));
}

test("a submission carries the facts, both slugs and the digest over the facts", async () => {
  const submission = await buildSubmission(entry("Comet Catcher Remake 1.8"));

  expect(submission.slug).toBe("comet-catcher-remake-1-8");
  expect(submission.slug_alternative).toMatch(/^comet-catcher-remake-1-8-[0-9a-f]{8}$/);
  expect(submission.facts_digest).toMatch(/^[0-9a-f]{64}$/);
  expect(submission.entry.map_name).toBe("Comet Catcher Remake 1.8");
});

/** The digest is over the facts alone. Folding the hub's own derivations into
 * it would make a moved slug read as changed facts. */
test("neither slug is part of what the digest covers", async () => {
  const submission = await buildSubmission(entry("Comet Catcher Remake 1.8"));

  expect(JSON.stringify(submission.entry)).not.toContain(submission.slug);
  expect(JSON.stringify(submission.entry)).not.toContain(submission.facts_digest);
});

/**
 * Every entry locks its map row before it reads it, so two batches holding the
 * same two maps in opposite orders would each hold what the other waits for and
 * Postgres would kill one of them. A settled order means they queue instead.
 */
test("a batch is sent in a settled order, whatever order it was asked in", async () => {
  const calls: Call[] = [];
  const supabase = fakeSupabase({ data: [], error: null }, calls);

  await submitMapFacts(supabase, await submissions("Zed 1.0", "Alpha 1.0", "Middle 1.0"), USER);

  expect(calls[0].name).toBe("submit_map_facts");
  expect(calls[0].args.p_submitted_by).toBe(USER);
  expect((calls[0].args.p_maps as MapSubmission[]).map((one) => one.entry.map_name)).toEqual([
    "Alpha 1.0",
    "Middle 1.0",
    "Zed 1.0",
  ]);
});

test("the outcomes come back keyed by name, so the route can answer in request order", async () => {
  const supabase = fakeSupabase({
    data: [
      { map_name: "Alpha 1.0", outcome: "stored", said: null },
      { map_name: "Zed 1.0", outcome: "conflict", said: null },
    ],
    error: null,
  });

  const written = await submitMapFacts(supabase, await submissions("Zed 1.0", "Alpha 1.0"), USER);

  expect(written.ok).toBe(true);
  if (!written.ok) return;
  expect(written.outcomes.get("Alpha 1.0")).toEqual({ outcome: "stored", said: null });
  expect(written.outcomes.get("Zed 1.0")).toEqual({ outcome: "conflict", said: null });
});

/** 53400 is the rate limit trigger's errcode, the one failure the caller answers
 * differently: nothing was written, so the client waits and sends the same batch
 * again. */
test("a rate limited batch is told apart from a database that is simply down", async () => {
  const limited = await submitMapFacts(
    fakeSupabase({ data: null, error: { code: "53400" } }),
    await submissions("Alpha 1.0"),
    USER,
  );
  expect(limited).toEqual({ ok: false, rateLimited: true });

  const down = await submitMapFacts(
    fakeSupabase({ data: null, error: { code: "57P01" } }),
    await submissions("Alpha 1.0"),
    USER,
  );
  expect(down).toEqual({ ok: false, rateLimited: false });
});
