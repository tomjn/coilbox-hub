import { ASSET_VOCABULARY_DIGEST } from "@/lib/assets/vocabularyDigest";
import { getSupabaseConfig } from "@/lib/supabase/config";

/**
 * What a client needs to run the Discord PKCE sign-in flow: which Supabase
 * project to talk to, and the publishable key for it. Neither is a secret -
 * both already ship in the website's own browser bundle (see
 * `lib/supabase/client.ts`) - this just lets a client discover them from the
 * hub address it was configured with, rather than a build baking in a second
 * constant that would fight coilbox's one configurable hub address.
 *
 * Carries the same `format`/`version` envelope as `/api/v1/items`, so an old
 * build can say the service is newer than it understands rather than
 * guessing at a shape that changed under it.
 */
export const AUTH_FORMAT = "coilbox-hub-auth";
export const AUTH_VERSION = 1;

export interface AuthBody {
  format: typeof AUTH_FORMAT;
  version: typeof AUTH_VERSION;
  supabase_url: string;
  publishable_key: string;
  /**
   * The asset vocabulary a client has to encode to, as a digest rather than as
   * its contents (#165). A client compares it against its own copy and reports
   * that it is out of date, and never follows what it reads: `encode_profile`
   * names bytes, so a profile that means different settings on different days
   * would break the identity `source_hash` and the have check rest on.
   *
   * Additive, so a client that reads no such field carries on unchanged. That
   * is why the version above did not move.
   */
  asset_vocabulary: string;
}

export type AuthResult = { ok: true; body: AuthBody } | { ok: false };

/**
 * Reads the two environment variables the client needs, through
 * `getSupabaseConfig()` (lib/supabase/config.ts) rather than its own copy of
 * the same check. If either is unset this deployment cannot answer, and
 * hands back `{ ok: false }` rather than a body with an `undefined` field: a
 * client that gets `"supabase_url": undefined` has nowhere useful to go and
 * nothing on screen to explain why.
 */
export function buildAuthBody(): AuthResult {
  const config = getSupabaseConfig();
  if (!config.ok) {
    return { ok: false };
  }

  return {
    ok: true,
    body: {
      format: AUTH_FORMAT,
      version: AUTH_VERSION,
      supabase_url: config.config.url,
      publishable_key: config.config.publishableKey,
      asset_vocabulary: ASSET_VOCABULARY_DIGEST,
    },
  };
}
