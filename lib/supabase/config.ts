/**
 * The one place that reads and validates the Supabase configuration every
 * client in this app needs. `middleware.ts`, `lib/supabase/client.ts`,
 * `lib/supabase/server.ts` and `lib/supabase/bearer.ts` all import this
 * instead of asserting `!` on the env vars themselves, which used to let a
 * missing variable reach the Supabase SDK as `undefined` and throw deep
 * inside it with no indication of what was actually wrong (issue 54).
 */
export interface SupabaseConfig {
  url: string;
  publishableKey: string;
}

export type SupabaseConfigResult =
  | { ok: true; config: SupabaseConfig }
  | { ok: false };

export const SUPABASE_CONFIG_ERROR =
  "This deployment has not configured Supabase. Set NEXT_PUBLIC_SUPABASE_URL and NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY.";

export const SUPABASE_SERVICE_ROLE_ERROR =
  "This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.";

/**
 * Reads `NEXT_PUBLIC_SUPABASE_URL` and `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY`.
 * Hands back `{ ok: false }` rather than a config with an `undefined` field,
 * the same shape `lib/api/auth.ts` already used for the same reason: a
 * caller that gets `undefined` where a URL should be has nowhere useful to
 * go, whereas `{ ok: false }` is something every caller can check for.
 */
export function getSupabaseConfig(): SupabaseConfigResult {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const publishableKey = process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;

  if (!url || !publishableKey) {
    return { ok: false };
  }

  return { ok: true, config: { url, publishableKey } };
}

/**
 * For the callers that cannot do anything useful without a config and would
 * otherwise have to repeat the same `if (!result.ok) ...` themselves: throws
 * a message naming the missing variables instead of letting `undefined`
 * reach the Supabase SDK.
 */
export function requireSupabaseConfig(): SupabaseConfig {
  const result = getSupabaseConfig();
  if (!result.ok) throw new Error(SUPABASE_CONFIG_ERROR);
  return result.config;
}

/**
 * The service role key, needed on top of `requireSupabaseConfig()` wherever
 * a server action acts as an admin rather than as the signed in user (account
 * deletion in `app/account/actions.ts`). Kept separate because most callers
 * never need it, and a deployment can have the public config right while
 * still missing this one.
 */
export function requireSupabaseServiceRoleKey(): string {
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!key) throw new Error(SUPABASE_SERVICE_ROLE_ERROR);
  return key;
}
