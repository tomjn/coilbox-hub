"use server";

import type { SupabaseClient } from "@supabase/supabase-js";
import { revalidatePath, updateTag } from "next/cache";
import { isModeratorRejectionKind } from "@/lib/assets/asset";
import { TAGS } from "@/lib/cache/tags";
import { approvePictures, pictureIds, rejectPicture } from "@/lib/assets/queue";
import { createClient } from "@/lib/supabase/server";

/**
 * The two writes the contact sheet makes (issues #114 and #115).
 *
 * Both of these are reachable as a plain POST rather than only through the grid,
 * so each one asks `is_moderator()` for itself. The page's own check is what
 * decides whether the grid renders, and it decides nothing about what a request
 * may do.
 *
 * Both write through the moderator's own session rather than the secret key,
 * which is #115's change and not a tidying up. `public.asset_event` records who
 * made every decision and it reads `auth.uid()`, so a write made as
 * `service_role` would log the decision with nobody behind it.
 */

/** The caller's own client, if the caller may act on the queue at all, and null
 * otherwise. `authenticated` still holds no update grant on `public.asset`: the
 * three moderation functions are security definer and ask this same question
 * again for themselves before they write. */
async function moderatorClient(): Promise<SupabaseClient | null> {
  const supabase = await createClient();
  const { data } = await supabase.rpc("is_moderator");
  return data === true ? supabase : null;
}

/**
 * Approve everything ticked, in one write.
 *
 * The whole point of the grid. A few hundred pictures of terrain and game
 * structures are one look and one click, so the reviewer keeps doing the job
 * rather than clicking through a card each.
 */
export async function approveSelected(form: FormData): Promise<void> {
  const supabase = await moderatorClient();
  if (!supabase) return;

  const ids = pictureIds(form.getAll("asset").map(String));
  await approvePictures(supabase, ids);

  revalidatePath("/moderation/assets");
  revalidatePath("/moderation/trail");
  // Approving is what makes a picture public, and it can appear on a map page,
  // the catalog or any item played on that map.
  updateTag(TAGS.assets);
}

/**
 * Reject one picture, which is what the reviewer does to the odd anomaly rather
 * than to a batch, saying which kind of rejection it is.
 *
 * The kind is the whole of #115 as far as this action is concerned. An
 * editorial rejection is a call about whether a picture belongs and a moderator
 * can put it back. A safety rejection is not a call, and nothing in the hub can
 * undo one: the row freezes, and the picture and its provenance stay where they
 * are for as long as anybody might have to produce them.
 *
 * An unrecognised kind is dropped rather than defaulted. Guessing which one a
 * malformed request meant is the one mistake worth refusing to make.
 */
export async function rejectOne(form: FormData): Promise<void> {
  const supabase = await moderatorClient();
  if (!supabase) return;

  const [id] = pictureIds(form.getAll("asset").map(String));
  if (!id) return;

  const kind = String(form.get("kind") ?? "");
  if (!isModeratorRejectionKind(kind)) return;

  await rejectPicture(supabase, id, kind);

  revalidatePath("/moderation/assets");
  revalidatePath("/moderation/trail");
  // A rejected picture was approved a moment ago if this is a correction, so
  // the pages drawing it have to stop.
  updateTag(TAGS.assets);
}
