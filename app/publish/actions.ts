"use server";

import { revalidatePath } from "next/cache";
import { accept } from "@/lib/gallery/publish";
import { createClient } from "@/lib/supabase/server";

export interface PublishState {
  error?: string;
  publishedId?: string;
}

/** Discord gives a display name under more than one key depending on whether the
 * account has a global name set. The row keeps whichever it had at publish time,
 * because an item should still name its author after the account is gone. */
function displayName(metadata: Record<string, unknown>): string {
  for (const key of ["full_name", "name", "preferred_username", "user_name"]) {
    const value = metadata[key];
    if (typeof value === "string" && value.trim() !== "") return value;
  }
  return "Unknown";
}

export async function publish(
  _previous: PublishState,
  form: FormData,
): Promise<PublishState> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) {
    return { error: "Sign in with Discord before publishing." };
  }

  const result = accept(String(form.get("code") ?? ""));
  if (!result.ok) {
    return { error: result.reason };
  }

  const title = String(form.get("title") ?? "").trim();
  if (title === "") {
    return { error: "Give it a title so people know what it is." };
  }

  const tags = String(form.get("tags") ?? "")
    .split(",")
    .map((tag) => tag.trim().toLowerCase())
    .filter((tag) => tag !== "")
    .slice(0, 8);

  const { accepted } = result;
  const { data, error } = await supabase
    .from("item")
    .insert({
      kind: accepted.kind,
      kind_version: accepted.kindVersion,
      title,
      description: String(form.get("description") ?? "").trim(),
      game_name: accepted.gameName,
      map_name: accepted.mapName,
      tags,
      container: accepted.container,
      author_id: user.id,
      author_name: displayName(user.user_metadata ?? {}),
    })
    .select("id")
    .single();

  if (error) {
    return { error: `Could not publish it: ${error.message}` };
  }

  revalidatePath("/");
  return { publishedId: data.id };
}
