import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { SubmittedUnit } from "@/lib/api/gameFacts";
import { buildGameSubmission, submitGameFacts, unitDigest } from "./submit";

const USER = "11111111-1111-1111-1111-111111111111";

function unit(name: string, overrides: Partial<SubmittedUnit> = {}): SubmittedUnit {
  return {
    name,
    full_name: null,
    faction_key: null,
    build_options: [],
    stats: {},
    morph_targets: [],
    ...overrides,
  };
}

interface Call {
  name: string;
  args: Record<string, unknown>;
}

/**
 * A stand in for the secret key client. What is under test here is the digest
 * and what the route does with the function's answers; the rules behind
 * `submit_game_facts` are proved in `supabase/tests/game_submission.test.sql`,
 * where they can run against real rows.
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

test("the digest is a hash over the normalised facts", async () => {
  const digest = await unitDigest(unit("armcom", { full_name: "Commander" }));
  expect(digest).toMatch(/^[0-9a-f]{64}$/);
  expect(await unitDigest(unit("armcom", { full_name: "Commander" }))).toBe(digest);
});

test("a changed stat changes the digest, and nothing else about the row does", async () => {
  const before = await unitDigest(unit("armcom", { stats: { health: 5000 } }));
  const after = await unitDigest(unit("armcom", { stats: { health: 4500 } }));
  expect(before).not.toBe(after);
});

test("absent and null optional text are one fact, not two spellings of it", async () => {
  const absent = await unitDigest(unit("armcom"));
  const nulled = await unitDigest(unit("armcom", { full_name: null }));
  expect(absent).toBe(nulled);
});

test("build options arrive at one digest whatever order they were read in", async () => {
  const one = await unitDigest(unit("armcom", { build_options: ["armmex", "armsolar"] }));
  const other = await unitDigest(unit("armcom", { build_options: ["armsolar", "armmex"] }));
  expect(one).toBe(other);
});

test("digests a unit whose morphs changed as different facts", async () => {
  const before = await unitDigest(unit("armcom", { morph_targets: [] }));
  const after = await unitDigest(
    unit("armcom", { morph_targets: [{ into: "armcom1" }] }),
  );
  expect(before).not.toBe(after);
});

test("one request carries one game and its whole batch to submit_game_facts", async () => {
  const calls: Call[] = [];
  const supabase = fakeSupabase(
    {
      data: [
        { kind: "faction", name: "armada", outcome: "accepted", said: null },
        { kind: "unit", name: "armcom", outcome: "unchanged", said: null },
        { kind: "unit", name: "armmex", outcome: "refused", said: "nope" },
      ],
      error: null,
    },
    calls,
  );

  const built = await buildGameSubmission({
    shortname: "BA",
    release: "1.9.0",
    complete: true,
    start_units: ["armcom"],
    factions: [{ key: "armada", name: "Armada" }],
    units: [unit("armcom"), unit("armmex")],
  });

  const written = await submitGameFacts(supabase, built, USER);

  expect(calls).toEqual([
    { name: "submit_game_facts", args: { p_submission: built, p_submitted_by: USER } },
  ]);
  expect(built.units[0].facts_digest).toMatch(/^[0-9a-f]{64}$/);
  expect(written.ok).toBe(true);
  if (!written.ok) return;

  // Request order, factions first, whatever order the function walked.
  expect(written.results.map((result) => `${result.kind}:${result.outcome}`)).toEqual([
    "faction:accepted",
    "unit:unchanged",
    "unit:refused",
  ]);
  expect(written.results[2].said).toBe("nope");
});

test("a failure from the database is one refusal for the request, not per entry", async () => {
  const supabase = fakeSupabase({ data: null, error: { code: "XX000" } });
  const written = await submitGameFacts(
    supabase,
    await buildGameSubmission({
      shortname: "BA",
      release: "1.9.0",
      complete: false,
      start_units: null,
      factions: null,
      units: [],
    }),
    USER,
  );
  expect(written.ok).toBe(false);
});
