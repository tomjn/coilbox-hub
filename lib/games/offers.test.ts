import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { decideDownloadOffer } from "./offers";

/**
 * Reading one call's answer into the four things that can have happened to an
 * offer (#408).
 *
 * The rules themselves live in `public.decide_game_download_offer` and are
 * proved against real roles in `supabase/tests/game_download_offer.test.sql`,
 * where an owner, a stranger and a moderator can each try it. What is under
 * test here is the part a page depends on: that "not yours to decide" and
 * "nothing left to decide" do not arrive as the same answer, because they are
 * different sentences to whoever pressed the button.
 */

interface Call {
  name: string;
  args: Record<string, unknown>;
}

function fakeSupabase(
  answer: { data: unknown; error: { code?: string; message?: string } | null },
  calls: Call[] = [],
): SupabaseClient {
  return {
    rpc: (name: string, args: Record<string, unknown>) => {
      calls.push({ name, args });
      return Promise.resolve(answer);
    },
  } as unknown as SupabaseClient;
}

test("an accepted offer comes back as decided, with the id and answer it was given", async () => {
  const calls: Call[] = [];
  const decision = await decideDownloadOffer(fakeSupabase({ data: true, error: null }, calls), 7, true);

  expect(decision).toBe("decided");
  expect(calls).toEqual([
    { name: "decide_game_download_offer", args: { p_offer_id: 7, p_accept: true } },
  ]);
});

test("an offer somebody else already decided is gone rather than an error", async () => {
  const decision = await decideDownloadOffer(fakeSupabase({ data: false, error: null }), 7, true);
  expect(decision).toBe("gone");
});

test("a caller without the right is refused, and is not reported as a failure", async () => {
  const decision = await decideDownloadOffer(
    fakeSupabase({ data: null, error: { code: "42501", message: "insufficient_privilege" } }),
    7,
    true,
  );
  expect(decision).toBe("refused");
});

test("anything else is a failure, so a page says try again rather than blaming the reader", async () => {
  const decision = await decideDownloadOffer(
    fakeSupabase({ data: null, error: { code: "57014", message: "canceling statement" } }),
    7,
    false,
  );
  expect(decision).toBe("failed");
});
