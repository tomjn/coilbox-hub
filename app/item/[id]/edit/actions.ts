"use server";

import { updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { TAGS } from "@/lib/cache/tags";
import { createClient } from "@/lib/supabase/server";

export interface EditState {
  error?: string;
}

/**
 * Editing covers the words around an item, not the item. Replacing the container
 * is a republish, because a changed payload is a different thing and swapping it
 * under an existing URL breaks whoever linked to what they saw. The database
 * enforces that with a column level grant, so this cannot drift from it.
 */
export async function saveItem(
  _previous: EditState,
  form: FormData,
): Promise<EditState> {
  const id = String(form.get("id") ?? "");
  const title = String(form.get("title") ?? "").trim();
  if (title === "") return { error: "It needs a title." };

  const supabase = await createClient();
  const { error } = await supabase
    .from("item")
    .update({
      title,
      description: String(form.get("description") ?? "").trim(),
      tags: String(form.get("tags") ?? "")
        .split(",")
        .map((tag) => tag.trim().toLowerCase())
        .filter(Boolean)
        .slice(0, 8),
    })
    .eq("id", id);

  if (error) return { error: `Could not save it: ${error.message}` };

  // The page this redirects to and every listing the item is on. `updateTag`
  // rather than `revalidateTag` because the author lands on the item a moment
  // later and has to see what they just wrote.
  updateTag(TAGS.item(id));
  updateTag(TAGS.items);
  redirect(`/item/${id}`);
}

/** Withdrawal is the soft delete. It stops being served and stops appearing, and
 * a moderator can still see what was there. The author keeps seeing it, so this
 * is reversible. */
export async function setWithdrawn(form: FormData): Promise<void> {
  const id = String(form.get("id") ?? "");
  const withdrawn = form.get("withdrawn") === "true";

  const supabase = await createClient();
  await supabase
    .from("item")
    .update({ deleted_at: withdrawn ? new Date().toISOString() : null })
    .eq("id", id);

  updateTag(TAGS.item(id));
  updateTag(TAGS.items);
  redirect(`/item/${id}`);
}
