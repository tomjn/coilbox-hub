"use server";

import { revalidatePath } from "next/cache";
import { pictureIds, returnPicture } from "@/lib/assets/queue";
import { createClient } from "@/lib/supabase/server";

/**
 * Put an editorial rejection back in the queue (issue #115).
 *
 * The only thing on the trail page that writes anything, and the reason the two
 * rejection kinds are worth telling apart in the first place. An editorial
 * rejection is somebody's call about whether a picture belongs, and calls get
 * made wrongly. A safety rejection is not a call.
 *
 * Nothing here decides which is which. `public.return_asset` refuses a safety
 * rejection, and `asset_safety_rejection_is_final` on the table refuses it
 * again underneath that, so the rule holds for a hand written POST exactly as
 * it does for this button.
 */
export async function returnToQueue(form: FormData): Promise<void> {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (allowed !== true) return;

  const [id] = pictureIds(form.getAll("asset").map(String));
  if (!id) return;

  await returnPicture(supabase, id);

  revalidatePath("/moderation/trail");
  revalidatePath("/moderation/assets");
}
