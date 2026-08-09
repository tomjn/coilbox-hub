"use server";

import { revalidatePath } from "next/cache";
import { headers } from "next/headers";
import { publishItem } from "@/lib/gallery/publish";
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

  const outcome = await publishItem(supabase, user.id, {
    code: values.code,
    title: values.title,
    description: values.description,
    tags: values.tags.split(","),
  });
  if (!outcome.ok) {
    return fail(outcome.reason);
  }

  const incoming = await headers();
  const host = incoming.get("host");
  const proto = incoming.get("x-forwarded-proto") ?? "https";

  revalidatePath("/");
  return {
    publishedId: outcome.item.id,
    shareUrl: host ? `${proto}://${host}/i/${outcome.item.id}` : undefined,
  };
}
