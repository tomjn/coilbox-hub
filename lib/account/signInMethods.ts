import type { UserIdentity } from "@supabase/supabase-js";
import { SIGN_IN_PROVIDERS, type SignInProviderId } from "@/lib/supabase/signInProvider";

/**
 * The ways into an account, for the list on /account (issue #435).
 */

export interface SignInMethod {
  identityId: string;
  provider: string;
  /** The provider's name to show, or its id for one the hub no longer offers. */
  label: string;
  /** The name or address the identity carries there, so two GitHub accounts can be told apart. */
  account: string | null;
}

/** What the identity is called at its provider, in the order the moderator directory reads them. */
export function identityAccount(identity: Pick<UserIdentity, "identity_data">): string | null {
  const data = identity.identity_data ?? {};
  for (const key of ["preferred_username", "user_name", "full_name", "name", "email"]) {
    const value = data[key];
    if (typeof value === "string" && value.trim() !== "") return value.trim();
  }
  return null;
}

export function signInMethods(identities: UserIdentity[]): SignInMethod[] {
  return identities.map((identity) => ({
    identityId: identity.identity_id,
    provider: identity.provider,
    label: SIGN_IN_PROVIDERS.find((p) => p.id === identity.provider)?.label ?? identity.provider,
    account: identityAccount(identity),
  }));
}

/** The offered providers this account has nothing linked from yet. */
export function linkableProviders(identities: Pick<UserIdentity, "provider">[]) {
  return SIGN_IN_PROVIDERS.filter((p) => !identities.some((identity) => identity.provider === p.id));
}

/**
 * An account has to keep a way in, so the last identity stays. Supabase refuses
 * it as well, and saying so first means the page can explain it.
 */
export function mayUnlink(identities: unknown[]): boolean {
  return identities.length > 1;
}

export const LAST_METHOD_REASON =
  "This is your only way to sign in, so it cannot be removed. Link another one first.";

/**
 * What the account page says after a link came back refused. `reason` is the
 * `error_code` Supabase sent to the callback, so anything not known here gets
 * the general sentence.
 */
export function linkFailureMessage(provider: SignInProviderId, reason: string | null): string {
  const label = SIGN_IN_PROVIDERS.find((p) => p.id === provider)?.label ?? provider;
  if (reason === "identity_already_exists") {
    return `That ${label} account is already linked to another account on the hub. Sign in with it to use that account, or link a different ${label} account.`;
  }
  return `Could not link your ${label} account. Try again.`;
}
