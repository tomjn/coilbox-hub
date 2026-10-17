"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { TAGS } from "@/lib/cache/tags";
import { mergeAuthorKeys, unmergeAuthorKey } from "@/lib/maps/authorMerge";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * Recording that several author keys are one person, and taking it back
 * (issues #193 and #442).
 *
 * Both ask `is_moderator()` for themselves, because both are reachable as a
 * plain POST, and both write with the secret key for the reason
 * `app/moderation/maps/actions.ts` gives.
 *
 * ## Why merging answers back and clearing does not
 *
 * A merge can be refused for a reason the moderator has to be told: the key they
 * kept is itself merged, so the merge they meant is into whoever that key
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
 * Merge the ticked keys into the one the moderator kept, and name the person.
 *
 * The name is the typed one if anything was typed, otherwise the spelling
 * picked from the list. `public.merge_author_keys` holds the one hop rule.
 *
 * The search goes back in the URL so the moderator lands on the list they were
 * working through. A merge that did not happen keeps the ticked keys too, so
 * the form is still open with the answer above it.
 */
export async function mergeAuthors(form: FormData): Promise<void> {
  if (!(await isModerator())) return;

  const keys = form.getAll("key").map(String);
  const typedName = String(form.get("typed_name") ?? "").trim();

  const outcome = await mergeAuthorKeys(
    createAdminClient(),
    String(form.get("into") ?? ""),
    keys,
    typedName === "" ? String(form.get("name") ?? "") : typedName,
    // Capped where it is read rather than by the column, which allows 2000. A
    // note is a sentence saying why these are one person.
    String(form.get("note") ?? "").slice(0, 500),
  );

  revalidatePath("/moderation/authors");
  // A merge changes the credits on every map that person made, so the whole
  // catalog and every map page go with it.
  updateTag(TAGS.maps);

  const next = new URLSearchParams({ outcome });
  const search = String(form.get("q") ?? "").trim();
  if (search !== "") next.set("q", search);
  if (outcome !== "merged") for (const key of keys) next.append("merge", key);

  redirect(`/moderation/authors?${next}`);
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
