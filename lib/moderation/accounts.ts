import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The account directory behind /moderation/users (issue #385).
 *
 * Everything here goes through the database function of the same name, and
 * `20260917140000_moderator_account_directory.sql` is the authority on what it
 * returns and who may call it. Nothing here re-checks `is_moderator()` on the
 * way in: the function refuses a non-moderator itself, so the page reads an
 * error rather than pretending to be a gatekeeper.
 */

/** How many accounts one page of the directory shows. The database function
 *  caps every answer at this number; a raised limit here would be a second
 *  number for the migration to keep in step with. */
export const ACCOUNT_PAGE_SIZE = 50;

/** One account, as the directory shows it. */
export interface ModeratorAccount {
  id: string;
  /** What Discord last reported, or "" where the account has nothing this hub
   *  can read. The page renders "Unknown" for that, the same word
   *  `displayName()` picks. */
  display_name: string;
  created_at: string;
  capabilities: string[];
}

/**
 * One page of accounts, newest first.
 *
 * Returns an error string rather than throwing, because a directory that
 * failed to load should read as a failure, not as an empty hub. The caller
 * distinguishes the two, which is the difference between "nobody has signed
 * up" and "the directory is unreachable".
 */
export async function fetchAccounts(
  supabase: SupabaseClient,
  search: string | null,
  offset: number,
): Promise<{ accounts: ModeratorAccount[]; error: string | null }> {
  const { data, error } = await supabase.rpc("moderator_accounts", {
    p_search: search,
    p_offset: offset,
  });

  if (error) return { accounts: [], error: error.message };
  return { accounts: (data ?? []) as unknown as ModeratorAccount[], error: null };
}
