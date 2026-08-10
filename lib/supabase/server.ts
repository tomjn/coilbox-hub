import { createServerClient } from "@supabase/ssr";
import { cookies } from "next/headers";
import { requireSupabaseConfig } from "@/lib/supabase/config";

/**
 * The server client, for route handlers, server components and server actions.
 * It reads and writes the session cookie so a signed in visitor is recognised
 * during server rendering, which is what makes per-item pages and their link
 * previews work without shipping the session to the browser first.
 */
export async function createClient() {
  const store = await cookies();
  const { url, publishableKey } = requireSupabaseConfig();

  return createServerClient(url, publishableKey, {
    cookies: {
      getAll() {
        return store.getAll();
      },
      setAll(written) {
        try {
          for (const { name, value, options } of written) {
            store.set(name, value, options);
          }
        } catch {
          // Server components cannot set cookies. The middleware refreshes the
          // session on every request, so there is nothing to recover here.
        }
      },
    },
  });
}
