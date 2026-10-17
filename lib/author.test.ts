import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { fetchDisplayName } from "./author";

/**
 * The display name is read from public.profile (issue #429), not from the
 * provider metadata on the session, so a rename shows at once and an account
 * with no Discord profile still has a name.
 */
function fakeSupabase(answer: { data: unknown; error: unknown }) {
  const calls: { table?: string; columns?: string; filter?: [string, string] } = {};
  const builder = {
    select: (columns: string) => {
      calls.columns = columns;
      return builder;
    },
    eq: (column: string, value: string) => {
      calls.filter = [column, value];
      return builder;
    },
    maybeSingle: async () => answer,
  };
  const supabase = {
    from: (table: string) => {
      calls.table = table;
      return builder;
    },
  } as unknown as SupabaseClient;
  return { supabase, calls };
}

test("reads the account's own row of public.profile", async () => {
  const { supabase, calls } = fakeSupabase({ data: { display_name: "Ada" }, error: null });
  expect(await fetchDisplayName(supabase, "abc")).toBe("Ada");
  expect(calls).toEqual({ table: "profile", columns: "display_name", filter: ["user_id", "abc"] });
});

test("an account with no profile row has no name, rather than a made up one", async () => {
  const { supabase } = fakeSupabase({ data: null, error: null });
  expect(await fetchDisplayName(supabase, "abc")).toBeNull();
});

test("a failed read has no name, rather than throwing out of the header", async () => {
  const { supabase } = fakeSupabase({ data: null, error: { message: "boom" } });
  expect(await fetchDisplayName(supabase, "abc")).toBeNull();
});
