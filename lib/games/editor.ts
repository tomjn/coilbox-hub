import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Whether a signed in account may change a game, and which row it may change
 * (#229, #242, #350). The game's owner may, and so may a moderator, on any game.
 *
 * Every page, action and route that offers or makes an owner's change asks
 * here, so a moderator never gets one of them and misses another. The client
 * must be the visitor's own, never the secret key: both questions are answered
 * by row level security, and a caller spends the secret key only after this
 * returns a game. A moderator can read a hidden game through
 * `game_read_visible`, so hiding a game does not lock them out of it.
 *
 * Null for a game that does not exist, which callers treat the same as a game
 * the visitor may not change.
 */
export async function editableGame(
  supabase: SupabaseClient,
  userId: string,
  shortname: string,
): Promise<{ id: string } | null> {
  const { data: moderator } = await supabase.rpc("is_moderator");

  let query = supabase.from("game").select("id").eq("shortname", shortname);
  if (moderator !== true) query = query.eq("owner_user_id", userId);

  const { data } = await query.maybeSingle();
  return data ? { id: (data as { id: string }).id } : null;
}
