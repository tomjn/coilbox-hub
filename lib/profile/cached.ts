import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";
import { getSupabaseConfig } from "@/lib/supabase/config";
import { type Contributions, loadContributions } from "./contributions";

/**
 * A profile's reads, held between requests. `lib/gallery/cached.ts` says why
 * these build their own anonymous client: the answer is the same for every
 * visitor by construction. Tagged with everything a profile counts, so
 * publishing, withdrawing, hiding, renaming and moderating a picture each
 * refresh it.
 */
export async function contributionsCached(userId: string): Promise<Contributions | null> {
  "use cache";
  cacheLife("hours");
  cacheTag(TAGS.items, TAGS.maps, TAGS.games, TAGS.assets);

  // `next build` prerenders a placeholder of this page and can run with no
  // Supabase settings, as CI does. It renders as not found then.
  if (!getSupabaseConfig().ok) return null;
  return loadContributions(createAnonClient(), createAdminClient(), userId);
}
