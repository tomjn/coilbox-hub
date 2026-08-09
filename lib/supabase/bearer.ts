import { createClient as createSupabaseClient, type SupabaseClient, type User } from "@supabase/supabase-js";

/**
 * Pulls the token out of `Authorization: Bearer <token>`. Pure and
 * network-free, so a missing header or a malformed scheme is told apart from
 * a token that was sent but turned out not to verify, without a live
 * Supabase stack to test against.
 */
export function extractBearerToken(request: Request): string | null {
  const header = request.headers.get("authorization");
  if (!header) return null;

  const match = /^Bearer\s+(.+)$/i.exec(header.trim());
  const token = match?.[1]?.trim();
  return token && token !== "" ? token : null;
}

/**
 * A client whose row level security runs as the user that token belongs to,
 * for the API surface (issue 25). The wildcard CORS origin in lib/api/cors.ts
 * cannot be combined with credentialed requests, so the API authenticates
 * with a plain `Authorization` header rather than the session cookie the
 * website uses - this is what turns that header into a client.
 *
 * Built on the publishable key, never the service role key: the service role
 * bypasses row level security entirely, which would undo issue 27 (the RLS
 * lockdown) in one line. `persistSession: false` because a route handler
 * starts fresh on every request, so there is nothing to persist to.
 */
export function createClientForToken(token: string): SupabaseClient {
  return createSupabaseClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY!,
    {
      auth: { autoRefreshToken: false, persistSession: false },
      global: { headers: { Authorization: `Bearer ${token}` } },
    },
  );
}

export type BearerAuthResult =
  | { ok: true; user: User; supabase: SupabaseClient }
  | { ok: false; reason: "missing" | "invalid" };

/**
 * Reads and verifies the bearer token on an incoming request. Never trusts
 * anything else in the request about who is publishing - `getUser(token)`
 * asks the auth server to verify the token itself, rather than decoding it
 * locally and hoping.
 */
export async function authenticateBearer(request: Request): Promise<BearerAuthResult> {
  const token = extractBearerToken(request);
  if (!token) return { ok: false, reason: "missing" };

  const supabase = createClientForToken(token);
  const { data, error } = await supabase.auth.getUser(token);
  if (error || !data.user) {
    return { ok: false, reason: "invalid" };
  }

  return { ok: true, user: data.user, supabase };
}
