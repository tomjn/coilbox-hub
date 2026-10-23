import { ASSET_VOCABULARY_DIGEST } from "@/lib/assets/vocabularyDigest";
import { MAP_CATALOG_DIGEST } from "@/lib/maps/catalogDigest";
import { getSupabaseConfig } from "@/lib/supabase/config";
import { SIGN_IN_PROVIDERS } from "@/lib/supabase/signInProvider";

/**
 * What a client needs to run the PKCE sign-in flow: which Supabase
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
  /**
   * The map catalog both sides agree on, as a digest rather than as its
   * contents (#185). A client compares it against its own copy before it sends
   * anything, and reports that it is out of date. It never follows what it
   * reads: the catalog decides what a fact is and what a metal spot is, so a
   * client that took its definitions from here would change what it reports
   * without its `catalogVersion` moving, and honest clients would then look
   * like they disagreed about the same archive.
   *
   * A mismatch is worth acting on but is not a refusal. The client keeps using
   * the catalog it shipped with and tells its user to update, because a copy it
   * can read is better than none, and the caps and the fact list it holds are
   * still the ones its own extraction was written against.
   *
   * Separate from `asset_vocabulary` above rather than folded into it, because
   * a client's response to each is different. One says it cannot encode a
   * picture correctly, the other says it cannot describe a map correctly, and
   * sharing a digest would make a clustering parameter stop every upload.
   *
   * Additive, so a client that reads no such field carries on unchanged. That
   * is why the version above did not move.
   */
  map_catalog: string;
  /**
   * The OAuth providers the hub has switched on, in the order to offer them
   * (#439). Each is the Supabase provider id, so a client can pass it straight
   * into an authorize URL. Additive, so the version above did not move.
   */
  providers: string[];
  /** Whether email and password sign in is available (#439). Additive too. */
  email_password: boolean;
}

/**
 * Whether this deployment offers email and password sign in. It must match
 * `[auth.email] enable_signup` in supabase/config.toml. The providers come from
 * `lib/supabase/signInProvider.ts`, the same list the website's buttons use.
 */
export const EMAIL_PASSWORD_ENABLED = false;

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
      map_catalog: MAP_CATALOG_DIGEST,
      providers: SIGN_IN_PROVIDERS.map((provider) => provider.id),
      email_password: EMAIL_PASSWORD_ENABLED,
    },
  };
}
