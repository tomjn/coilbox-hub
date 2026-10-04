import type { SupabaseClient } from "@supabase/supabase-js";
import { isModerator } from "@/lib/supabase/user";

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
  return findEditableGame(supabase, userId, shortname, moderator === true);
}

/**
 * `editableGame` for a page that decides what to draw, which takes the
 * request's one answer to "is this a moderator" instead of asking again.
 *
 * Not for an action or a route that writes: those call `editableGame`, which
 * asks the database itself, and some of them hold a client that is not the
 * one the request's cookies belong to. The client here must be the cookie
 * client, since `isModerator` answers for that visitor.
 */
export async function editableGameForPage(
  supabase: SupabaseClient,
  userId: string,
  shortname: string,
): Promise<{ id: string } | null> {
  return findEditableGame(supabase, userId, shortname, await isModerator());
}

async function findEditableGame(
  supabase: SupabaseClient,
  userId: string,
  shortname: string,
  moderator: boolean,
): Promise<{ id: string } | null> {
  let query = supabase.from("game").select("id").eq("shortname", shortname);
  if (!moderator) query = query.eq("owner_user_id", userId);

  const { data } = await query.maybeSingle();
  return data ? { id: (data as { id: string }).id } : null;
}
