import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * An account's display name, from public.profile (issue #429).
 *
 * The same row `current_author_name()` reads when somebody publishes, so the
 * header, the account page and an item published afterwards all agree. Null
 * when the read fails or finds no row, and the caller decides what to show,
 * because a header that throws takes every page down with it.
 */
export async function fetchDisplayName(
  supabase: SupabaseClient,
  userId: string,
): Promise<string | null> {
  const { data, error } = await supabase
    .from("profile")
    .select("display_name")
    .eq("user_id", userId)
    .maybeSingle();
  if (error || !data) return null;
  return (data as { display_name: string }).display_name;
}
