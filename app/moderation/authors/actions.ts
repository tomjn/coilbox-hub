"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { TAGS } from "@/lib/cache/tags";
import { mergeAuthorKeys, unmergeAuthorKey } from "@/lib/maps/authorMerge";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * Recording that two author keys are one person, and taking it back (issue
 * #193).
 *
 * Both ask `is_moderator()` for themselves, because both are reachable as a
 * plain POST, and both write with the secret key for the reason
 * `app/moderation/maps/actions.ts` gives.
 *
 * ## Why merging answers back and clearing does not
 *
 * A merge can be refused for a reason the moderator has to be told: the key they
 * aimed at is itself merged, so the merge they meant is into whoever that key
 * points at. There is no client JavaScript on this page and a server action
 * returns nothing to a form, so the answer rides back in the URL, the same way
 * `app/account/actions.ts` reports a deleted account.
 *
 * Every merge redirects, including the one that worked, so the line the page
 * shows is always about the submission that was just made rather than a stale
 * one from a URL somebody came back to.
 */

async function isModerator(): Promise<boolean> {
  const supabase = await createClient();
  const { data } = await supabase.rpc("is_moderator");

  return data === true;
}

/**
 * Point one author key at another.
 *
 * Both keys are folded by `public.author_key` before anything is written, so a
 * moderator typing `Beherith` records the same merge as one typing `beherith`.
 * `lib/maps/authorMerge.ts` holds that and the one hop rule.
 */
export async function mergeAuthors(form: FormData): Promise<void> {
  if (!(await isModerator())) return;

  const outcome = await mergeAuthorKeys(
    createAdminClient(),
    String(form.get("from") ?? ""),
    String(form.get("to") ?? ""),
    // Capped where it is read rather than by the column, which allows 2000. A
    // note is a sentence saying why these are one person.
    String(form.get("note") ?? "").slice(0, 500),
  );

  revalidatePath("/moderation/authors");
  // A merge changes the credits on every map that person made, so the whole
  // catalog and every map page go with it.
  updateTag(TAGS.maps);
  redirect(`/moderation/authors?outcome=${outcome}`);
}

/**
 * Withdraw a merge, so the two keys count separately again.
 *
 * No answer to report. Either the row is gone, which the list shows by no longer
 * listing it, or nothing was there to remove.
 */
export async function unmergeAuthors(form: FormData): Promise<void> {
  if (!(await isModerator())) return;

  const fromKey = String(form.get("from") ?? "");
  if (fromKey === "") return;

  await unmergeAuthorKey(createAdminClient(), fromKey);

  revalidatePath("/moderation/authors");
  // A merge changes the credits on every map that person made, so the whole
  // catalog and every map page go with it.
  updateTag(TAGS.maps);
}
