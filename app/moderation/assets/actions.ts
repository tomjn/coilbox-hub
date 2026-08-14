"use server";

import { revalidatePath } from "next/cache";
import { approvePictures, pictureIds, rejectPicture } from "@/lib/assets/queue";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The two writes the contact sheet makes (issue #114).
 *
 * Both of these are reachable as a plain POST rather than only through the grid,
 * so each one asks `is_moderator()` for itself. The page's own check is what
 * decides whether the grid renders, and it decides nothing about what a request
 * may do.
 */

/** Whether the caller may act on the queue at all. Asked through the session
 * client, which is the only client with a person behind it: `createAdminClient`
 * bypasses row level security and would answer for nobody. */
async function mayModerate(): Promise<boolean> {
  const supabase = await createClient();
  const { data } = await supabase.rpc("is_moderator");
  return data === true;
}

/**
 * Approve everything ticked, in one write.
 *
 * The whole point of the grid. A few hundred pictures of terrain and game
 * structures are one look and one click, so the reviewer keeps doing the job
 * rather than clicking through a card each.
 */
export async function approveSelected(form: FormData): Promise<void> {
  if (!(await mayModerate())) return;

  const ids = pictureIds(form.getAll("asset").map(String));
  await approvePictures(createAdminClient(), ids);

  revalidatePath("/moderation/assets");
  revalidatePath("/gallery");
}

/**
 * Reject one picture, which is what the reviewer does to the odd anomaly rather
 * than to a batch.
 *
 * Rejection in full is #115, and this is only as much of it as the grid needs to
 * work: without it the anomaly stays pending and comes back at the top of every
 * page, which is how a queue stops being read.
 */
export async function rejectOne(form: FormData): Promise<void> {
  if (!(await mayModerate())) return;

  const [id] = pictureIds(form.getAll("asset").map(String));
  if (!id) return;

  await rejectPicture(createAdminClient(), id);

  revalidatePath("/moderation/assets");
}
