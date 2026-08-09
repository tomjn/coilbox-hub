"use server";

import { revalidatePath } from "next/cache";
import { headers } from "next/headers";
import { accept } from "@/lib/gallery/publish";
import { createClient } from "@/lib/supabase/server";

export interface PublishState {
  error?: string;
  publishedId?: string;
  /** The durable URL for the new item, built here because the server knows the
   * host and the browser would have to guess it after hydration. */
  shareUrl?: string;
  /** What was submitted, handed back so a rejected form comes back filled in.
   * React resets uncontrolled inputs after a form action, so without this a
   * typo in the share link costs you the description you just wrote. */
  values?: {
    code: string;
    title: string;
    description: string;
    tags: string;
  };
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
  const values = {
    code: String(form.get("code") ?? ""),
    title: String(form.get("title") ?? ""),
    description: String(form.get("description") ?? ""),
    tags: String(form.get("tags") ?? ""),
  };
  const fail = (error: string): PublishState => ({ error, values });

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) {
    return fail("Sign in with Discord before publishing.");
  }

  const result = accept(values.code);
  if (!result.ok) {
    return fail(result.reason);
  }

  const title = values.title.trim();
  if (title === "") {
    return fail("Give it a title so people know what it is.");
  }

  const tags = values.tags
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
      description: values.description.trim(),
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
    return fail(`Could not publish it: ${error.message}`);
  }

  const incoming = await headers();
  const host = incoming.get("host");
  const proto = incoming.get("x-forwarded-proto") ?? "https";

  revalidatePath("/");
  return {
    publishedId: data.id,
    shareUrl: host ? `${proto}://${host}/i/${data.id}` : undefined,
  };
}
