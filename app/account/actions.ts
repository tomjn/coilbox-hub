"use server";

import { createClient as createAdminClient } from "@supabase/supabase-js";
import { updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { confirmsDeletion } from "@/lib/account/confirmDeletion";
import { checkDisplayName, renameErrorMessage } from "@/lib/account/displayName";
import { clearNav, writeNav } from "@/lib/auth/nav";
import { fetchDisplayName } from "@/lib/author";
import { TAGS } from "@/lib/cache/tags";
import { createClient } from "@/lib/supabase/server";
import { requireSupabaseConfig, requireSupabaseServiceRoleKey } from "@/lib/supabase/config";

export interface DeleteState {
  error?: string;
}

export interface RenameState {
  error?: string;
  /** The name now held, set after a save so the form can say so. */
  saved?: string;
  /** What was typed, so a refused name is still in the box to edit. */
  typed?: string;
}

/**
 * Changes the signed in account's display name (issue #434).
 *
 * This uses the session client, not the service role one, so the profile
 * table's own policy decides who may write which row. The row is picked by the
 * session's id and never by the form. Items already published keep the
 * `author_name` they were published under.
 */
export async function renameAccount(
  _previous: RenameState,
  form: FormData,
): Promise<RenameState> {
  const raw = form.get("displayName");
  const typed = typeof raw === "string" ? raw : undefined;
  const checked = checkDisplayName(raw);
  if ("error" in checked) return { error: checked.error, typed };

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/publish");

  const { data, error } = await supabase
    .from("profile")
    .update({ display_name: checked.name })
    .eq("user_id", user.id)
    .select("display_name");
  if (error) return { error: renameErrorMessage(error.code), typed };
  // Row level security hides a row it refuses rather than failing, so no row
  // back means nothing was written.
  if (!data || data.length === 0) return { error: renameErrorMessage(undefined), typed };

  // `accountDisplayName` caches the gallery heading under the listing's tag.
  updateTag(TAGS.items);
  // The header shows the name from `hub_nav`.
  await writeNav(supabase);
  return { saved: checked.name };
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

  // The same profile read the page made, so the name asked for is the one shown.
  const name = await fetchDisplayName(supabase, user.id);
  if (!name) {
    return { error: "Could not read your display name, so nothing was deleted. Try again." };
  }
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
  await clearNav();
  redirect("/?deleted=1");
}
