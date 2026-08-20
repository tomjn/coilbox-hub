"use server";

import { revalidatePath, updateTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { createClient } from "@/lib/supabase/server";

/** Reporting needs no account, because the person best placed to notice
 * something wrong is usually just browsing. */
export async function report(form: FormData): Promise<void> {
  const reason = String(form.get("reason") ?? "").trim();
  if (reason === "") return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  await supabase.from("report").insert({
    item_id: String(form.get("item_id") ?? ""),
    reason: reason.slice(0, 1000),
    reporter_id: user?.id ?? null,
  });

  updateTag(TAGS.item(String(form.get("item_id") ?? "")));
}

/** Withdrawing and marking handled are one action. Leaving a report open after
 * acting on it just means reading it again later. */
export async function actOnReport(form: FormData): Promise<void> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return;

  const id = String(form.get("report_id") ?? "");
  const itemId = String(form.get("item_id") ?? "");

  if (form.get("withdraw") === "true") {
    await supabase
      .from("item")
      .update({ deleted_at: new Date().toISOString() })
      .eq("id", itemId);
  }

  await supabase
    .from("report")
    .update({ handled_at: new Date().toISOString(), handled_by: user.id })
    .eq("id", id);

  // The queue is read per request, so it only needs the router cache clearing.
  // A withdrawn item is held, and every listing it was on is too.
  revalidatePath("/moderation");
  updateTag(TAGS.item(itemId));
  updateTag(TAGS.items);
}
