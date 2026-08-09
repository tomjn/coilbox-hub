import { createServerClient } from "@supabase/ssr";
import { cookies } from "next/headers";

/**
 * The server client, for route handlers, server components and server actions.
 * It reads and writes the session cookie so a signed in visitor is recognised
 * during server rendering, which is what makes per-item pages and their link
 * previews work without shipping the session to the browser first.
 */
export async function createClient() {
  const store = await cookies();

  return createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY!,
    {
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
    },
  );
}
