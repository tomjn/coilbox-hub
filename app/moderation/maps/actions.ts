"use server";

import { revalidatePath, updateTag } from "next/cache";
import { isUuid } from "@/lib/assets/queue";
import { TAGS } from "@/lib/cache/tags";
import { MAP_FEATURED_MESSAGES, type MapFormState } from "@/lib/maps/featured";
import { clearMapFacts, parseCuratedTags, setCuratedTags } from "@/lib/maps/moderation";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The three writes the map moderation pages make (issue #193, #394).
 *
 * All three are reachable as a plain POST rather than only through the page, so
 * each one asks `is_moderator()` for itself. The page's own check decides
 * whether the page renders and decides nothing about what a request may do.
 *
 * The first two write with the secret key rather than the moderator's own
 * session, which is the other way round from `app/moderation/assets/actions.ts`
 * and for a plain reason: there is no map equivalent of `public.asset_event`, so
 * there is no `auth.uid()` for a write to record. `authenticated` holds no write
 * on `public.map` or `public.author_alias` and this does not give it one.
 *
 * `setMapFeatured` is the exception, and it still writes with the secret key
 * rather than the moderator's own session. `public.map` already grants
 * `service_role` select, insert and update in full
 * (20260818100000_map_catalog.sql), which is the same grant
 * `setGameFeatured` (`app/games/actions.ts`) spends after its own
 * `is_moderator` check, and `featured_by` records the moderator from the
 * session asking rather than from anything a request could forge.
 */

/** Whether the caller may act on the catalog at all, asked of the caller's own
 *  session rather than of the secret key, which would answer for the hub. */
async function isModerator(): Promise<boolean> {
  const supabase = await createClient();
  const { data } = await supabase.rpc("is_moderator");

  return data === true;
}

/**
 * Forget everything the hub holds about one map.
 *
 * For the case the queue exists for: the held facts are the wrong ones, so every
 * honest client reporting the real archive is refused, and nothing else can
 * unstick it. `public.clear_map_facts` refuses a map nobody has disagreed about,
 * so a hand written post naming any other map does nothing.
 *
 * One map per submission and no way to name several. `lib/maps/moderation.ts`
 * says why: clearing a page of conflicts without reading them is the mistake
 * worth making impossible rather than convenient.
 */
export async function clearHeldFacts(form: FormData): Promise<void> {
  if (!(await isModerator())) return;

  const id = String(form.get("map") ?? "");
  if (!isUuid(id)) return;

  await clearMapFacts(createAdminClient(), id);

  revalidatePath("/moderation/maps");
  // Every map page and the catalog: a map whose facts are gone is a map with no
  // page, and it drops out of the listing at the same moment.
  updateTag(TAGS.maps);
}

/**
 * Put a maintainer's tags on one map.
 *
 * The whole field is the answer, so removing a tag is deleting it from the box.
 * A form that added one tag at a time would need a control per tag to take one
 * off again, and this is a short list somebody edits a handful of times.
 */
export async function saveCuratedTags(form: FormData): Promise<void> {
  if (!(await isModerator())) return;

  const id = String(form.get("map") ?? "");
  if (!isUuid(id)) return;

  const slug = String(form.get("slug") ?? "");
  const tags = parseCuratedTags(String(form.get("tags") ?? ""));

  await setCuratedTags(createAdminClient(), id, tags);

  revalidatePath(`/moderation/maps/${slug}`);
  // The map's own page and the catalog, where its chips are drawn.
  updateTag(TAGS.map(slug));
  updateTag(TAGS.maps);
}

/**
 * Put a map at the top of the catalog listing, or take it back down (#394).
 *
 * Checked against the caller's own session rather than trusted from the page,
 * for the same reason the two writes above are: this is reachable as a plain
 * POST, and the page's own `is_moderator` check decides only whether the page
 * renders.
 */
export async function setMapFeatured(
  _previous: MapFormState | null,
  form: FormData,
): Promise<MapFormState> {
  const id = String(form.get("id") ?? "").trim();
  const slug = String(form.get("slug") ?? "").trim();
  const featured = form.get("featured") === "true";
  if (!isUuid(id)) return { ok: false, message: MAP_FEATURED_MESSAGES.notSent };

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, message: MAP_FEATURED_MESSAGES.signedOut };

  const { data: moderator } = await supabase.rpc("is_moderator");
  if (moderator !== true) return { ok: false, message: MAP_FEATURED_MESSAGES.notAllowed };

  // Both columns ride one update, and unfeaturing clears both, so a map back on
  // the ordinary side of the listing never carries a stale name.
  const { data, error } = await createAdminClient()
    .from("map")
    .update(
      featured
        ? { featured_at: new Date().toISOString(), featured_by: user.id }
        : { featured_at: null, featured_by: null },
    )
    .eq("id", id)
    .select("id");

  if (error) {
    console.error(`setMapFeatured: ${id} was not updated`, error);
    return { ok: false, message: MAP_FEATURED_MESSAGES.notSaved };
  }
  if (!data || data.length === 0) {
    return { ok: false, message: MAP_FEATURED_MESSAGES.notFound };
  }

  updateTag(TAGS.maps);
  revalidatePath("/moderation/maps");
  if (slug) {
    updateTag(TAGS.map(slug));
    revalidatePath(`/moderation/maps/${slug}`);
  }

  return {
    ok: true,
    message: featured ? MAP_FEATURED_MESSAGES.featured : MAP_FEATURED_MESSAGES.unfeatured,
  };
}
