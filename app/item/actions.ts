"use server";

import { updateTag } from "next/cache";
import {
  ITEM_FEATURED_MESSAGES,
  type ItemFormState,
} from "@/lib/gallery/featured";
import { TAGS } from "@/lib/cache/tags";
import { createClient } from "@/lib/supabase/server";

/**
 * Put a published item at the top of the gallery, or take it back down (#395).
 *
 * The write is `public.set_item_featured`, called with the visitor's own
 * client. That is the opposite way round from `setGameFeatured`
 * (`app/games/actions.ts`), which asks `is_moderator` here and then writes with
 * the secret key, and the reason is what each table grants. `service_role`
 * holds nothing at all on `public.item` and has not since 20260810100000, so
 * there is no secret key path to guard. The function is security definer,
 * refuses anybody without `can_moderate` itself, and takes the moderator from
 * the session rather than from anything this sends, which also means a stranger
 * gets a refusal from the database rather than from whichever page remembered
 * to check.
 */
export async function setItemFeatured(
  _previous: ItemFormState | null,
  form: FormData,
): Promise<ItemFormState> {
  const id = String(form.get("id") ?? "").trim();
  const featured = form.get("featured") === "true";
  if (!id) return { ok: false, message: ITEM_FEATURED_MESSAGES.notSent };

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, message: ITEM_FEATURED_MESSAGES.signedOut };

  const { data, error } = await supabase.rpc("set_item_featured", {
    item_id: id,
    featured,
  });

  if (error) {
    // The one refusal that is not a failure: the function raises
    // insufficient_privilege for a session without can_moderate.
    if (error.code === "42501") {
      return { ok: false, message: ITEM_FEATURED_MESSAGES.notAllowed };
    }
    console.error(`setItemFeatured: ${id} was not updated`, error);
    return { ok: false, message: ITEM_FEATURED_MESSAGES.notSaved };
  }

  if (data !== true) {
    return { ok: false, message: ITEM_FEATURED_MESSAGES.nothingChanged };
  }

  // The item's own page and every listing it appears on, since where it sits
  // in the gallery is exactly what just changed. `updateTag` rather than
  // `revalidateTag` because the moderator is looking at one of those pages.
  updateTag(TAGS.item(id));
  updateTag(TAGS.items);

  return {
    ok: true,
    message: featured
      ? ITEM_FEATURED_MESSAGES.featured
      : ITEM_FEATURED_MESSAGES.unfeatured,
  };
}
