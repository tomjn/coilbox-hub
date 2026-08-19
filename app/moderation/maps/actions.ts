"use server";

import { revalidatePath } from "next/cache";
import { isUuid } from "@/lib/assets/queue";
import { clearMapFacts, parseCuratedTags, setCuratedTags } from "@/lib/maps/moderation";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The two writes the map moderation pages make (issue #193).
 *
 * Both are reachable as a plain POST rather than only through the page, so each
 * one asks `is_moderator()` for itself. The page's own check decides whether the
 * page renders and decides nothing about what a request may do.
 *
 * Both write with the secret key rather than the moderator's own session, which
 * is the other way round from `app/moderation/assets/actions.ts` and for a plain
 * reason: there is no map equivalent of `public.asset_event`, so there is no
 * `auth.uid()` for a write to record. `authenticated` holds no write on
 * `public.map` or `public.author_alias` and this does not give it one.
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
  revalidatePath("/maps");
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
  revalidatePath(`/map/${slug}`);
  revalidatePath("/maps");
}
