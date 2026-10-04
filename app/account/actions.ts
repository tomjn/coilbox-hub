"use server";

import { createClient as createAdminClient } from "@supabase/supabase-js";
import { updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { confirmsDeletion } from "@/lib/account/confirmDeletion";
import { displayName } from "@/lib/author";
import { TAGS } from "@/lib/cache/tags";
import { createClient } from "@/lib/supabase/server";
import { requireSupabaseConfig, requireSupabaseServiceRoleKey } from "@/lib/supabase/config";

export interface DeleteState {
  error?: string;
}

/**
 * Holding a Discord identity means owing people a way out, and per item
 * withdrawal is not it.
 *
 * This is a hard delete of the account and everything published under it. The
 * item table cascades from auth.users, so removing the user removes the rows in
 * one step rather than two that could half succeed.
 *
 * It needs the service role key because deleting an auth user is not something a
 * session can do to itself. The key never reaches the browser: this runs on the
 * server and the identity being deleted is read from the session, never from the
 * form, so this cannot be pointed at somebody else.
 *
 * The form also has to carry the account's display name, typed by the visitor.
 * That is checked here rather than in the browser so a post without it, from a
 * browser with scripting off or from anywhere else, deletes nothing.
 */
export async function deleteAccount(
  _previous: DeleteState,
  form: FormData,
): Promise<DeleteState> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/");

  // An account with no name falls back to "Unknown", and the page asks for that.
  const name = displayName(user.user_metadata ?? {});
  if (!confirmsDeletion(form.get("confirm"), name)) {
    return { error: `That is not your name. Type ${name} exactly to delete your account.` };
  }

  const { url } = requireSupabaseConfig();
  const serviceRoleKey = requireSupabaseServiceRoleKey();
  const admin = createAdminClient(url, serviceRoleKey, {
    auth: { autoRefreshToken: false, persistSession: false },
  });

  const { error } = await admin.auth.admin.deleteUser(user.id);
  if (error) throw new Error(`Could not delete the account: ${error.message}`);

  // The item table cascades from auth.users, so everything this person
  // published has just gone from every listing that was holding it.
  updateTag(TAGS.items);

  await supabase.auth.signOut();
  redirect("/?deleted=1");
}
