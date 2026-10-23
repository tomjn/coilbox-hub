"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { LAST_METHOD_REASON, mayUnlink } from "@/lib/account/signInMethods";
import { createClient } from "@/lib/supabase/server";

export interface UnlinkState {
  error?: string;
}

/**
 * Removes one way of signing in from this account (issue #435).
 *
 * The identity the form names has to be one of this account's own, read from
 * the session, and not the last one. Supabase Auth refuses the last one too.
 * An account with an authenticator has to have entered its code this session,
 * the same rule as removing an authenticator.
 */
export async function unlinkSignInMethod(
  _previous: UnlinkState,
  form: FormData,
): Promise<UnlinkState> {
  const supabase = await createClient();
  const { data: level } = await supabase.auth.mfa.getAuthenticatorAssuranceLevel();
  if (!level?.currentLevel) redirect("/publish");
  if (level.nextLevel === "aal2" && level.currentLevel !== "aal2") {
    return {
      error: "Enter a code from your authenticator before changing how you sign in.",
    };
  }

  const { data, error: listError } = await supabase.auth.getUserIdentities();
  if (listError || !data) return { error: "Could not read how you sign in. Try again." };
  const owned = data.identities.find((identity) => identity.identity_id === form.get("identityId"));
  if (!owned) return { error: "That is not one of the ways you sign in." };
  if (!mayUnlink(data.identities)) return { error: LAST_METHOD_REASON };

  const { error } = await supabase.auth.unlinkIdentity(owned);
  if (error) return { error: "Could not remove that way of signing in. Try again." };

  revalidatePath("/account");
  return {};
}
