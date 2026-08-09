"use server";

import { createClient as createAdminClient } from "@supabase/supabase-js";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";

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
 */
export async function deleteAccount(): Promise<void> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/");

  const admin = createAdminClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.SUPABASE_SERVICE_ROLE_KEY!,
    { auth: { autoRefreshToken: false, persistSession: false } },
  );

  const { error } = await admin.auth.admin.deleteUser(user.id);
  if (error) throw new Error(`Could not delete the account: ${error.message}`);

  await supabase.auth.signOut();
  redirect("/?deleted=1");
}
