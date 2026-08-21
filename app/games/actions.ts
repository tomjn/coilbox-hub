"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { readImageHeader, IMAGE_HEADER_BYTES } from "@/lib/assets/imageHeader";
import { putBlobGameImage } from "@/lib/assets/blob";
import { encodedHash } from "@/lib/assets/hash";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import type { GameLink } from "@/lib/games/catalog";

/**
 * The writes behind ownership (#229): asking, deciding, and what an owner may
 * change once they hold the pen.
 *
 * Every action here is thin on purpose. The rules live in constraints and
 * policies - one open ask per person per game, nobody asking as somebody else,
 * only a moderator deciding, an owner editing only their own row and only the
 * columns the grant names - and `supabase/tests/game_ownership.test.sql` proves
 * them against real roles. What is left here is reading the form, doing the one
 * check a policy cannot (who is asking at all), and writing.
 */

/** The labelled links an edit form carries, as rows.
 *
 * The form posts parallel `label` and `url` arrays, so position pairs them. A
 * pair with either half blank is not half a link: it is dropped, because a link
 * that renders as an empty anchor is worse than no link. */
function linksFromForm(form: FormData): GameLink[] {
  const labels = form.getAll("label").map(String);
  const urls = form.getAll("url").map(String);
  const links: GameLink[] = [];
  for (const [index, label] of labels.entries()) {
    const url = urls[index] ?? "";
    if (label.trim() === "" || url.trim() === "") continue;
    links.push({ label: label.trim(), url: url.trim() });
  }
  return links.slice(0, 12);
}

/** The secret key client, or null when this deployment has none. Two actions
 *  need it and neither has an error page to show, so both would rather do
 *  nothing than throw. */
function createAdmin(): ReturnType<typeof createAdminClient> | null {
  try {
    return createAdminClient();
  } catch {
    return null;
  }
}

export async function requestOwnership(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  const note = String(form.get("note") ?? "").trim().slice(0, 2000);
  if (!shortname) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  const { data: game } = await supabase
    .from("game")
    .select("id")
    .eq("shortname", shortname)
    .maybeSingle();
  if (!game) return;

  // The policy refuses a second open ask and a forged requester; both arrive
  // here as a failed write, which for this action is the right answer. The
  // button says what it does and the queue says what happened.
  await supabase.from("game_ownership_request").insert({
    game_id: game.id,
    requested_by: user.id,
    note: note || null,
  });

  revalidatePath(`/games/${shortname}`);
}

export async function decideRequest(form: FormData): Promise<void> {
  const id = String(form.get("request_id") ?? "");
  const approve = form.get("approve") === "true";
  if (!id) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return;
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) return;

  const admin = createAdmin();
  if (!admin) return;

  // Read first, as the secret key: the decision needs the game's shortname to
  // point the owner at, and the update itself has to move ownership in the same
  // breath as the state, or an approved ask could sit beside an unowned game.
  const { data: request } = await admin
    .from("game_ownership_request")
    .select("game_id,state,requested_by,game(shortname)")
    .eq("id", Number(id))
    .maybeSingle();
  if (!request || request.state !== "open") return;

  const shortname = (request.game as unknown as { shortname: string } | null)?.shortname;

  const { error } = await admin
    .from("game_ownership_request")
    .update({
      state: approve ? "approved" : "declined",
      decided_by: user.id,
      decided_at: new Date().toISOString(),
    })
    .eq("id", Number(id));
  if (error) return;

  if (approve) {
    await admin
      .from("game")
      .update({ owner_user_id: request.requested_by })
      .eq("id", request.game_id);
  }

  revalidatePath("/moderation/games");
  if (shortname) revalidatePath(`/games/${shortname}`);
}

export async function editGameDetails(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  if (!shortname) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  const displayName = String(form.get("display_name") ?? "").trim().slice(0, 256);

  // The owner-scoped policy filters every row but theirs, so a stranger's edit
  // succeeds over nothing. Returning the rows is how the action knows whether
  // it was the owner writing or a passer-by.
  const { data } = await supabase
    .from("game")
    .update({
      display_name: displayName || null,
      description: String(form.get("description") ?? "").trim().slice(0, 4000) || null,
      links: linksFromForm(form),
    })
    .eq("shortname", shortname)
    .select("shortname");

  if (data && data.length > 0) {
    revalidatePath(`/games/${shortname}`);
    revalidatePath("/games");
  }
}

export async function setSnippet(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  const unitName = String(form.get("unit_name") ?? "");
  if (!shortname || !unitName) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  const snippet = String(form.get("snippet") ?? "").trim().slice(0, 2000);

  const { data: game } = await supabase
    .from("game")
    .select("id")
    .eq("shortname", shortname)
    .maybeSingle();
  if (!game) return;

  const { data } = await supabase
    .from("game_unit")
    .update({ snippet: snippet || null })
    .eq("game_id", game.id)
    .eq("unit_name", unitName)
    .select("unit_name");

  if (data && data.length > 0) {
    revalidatePath(`/games/${shortname}/units/${unitName}`);
  }
}

/** The most bytes a logo or banner may be.
 *
 * A logo renders at 24 pixels and a banner across one column; anything past
 * half a megabyte is not a logo, it is an uncompressed screenshot, and refusing
 * it costs nothing honest. */
const MAX_IMAGE_BYTES = 512 * 1024;

export async function uploadGameImage(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  const kind = String(form.get("kind") ?? "");
  if (!shortname || (kind !== "logo" && kind !== "banner")) return;

  const file = form.get("image");
  if (!(file instanceof File) || file.size === 0) return;
  if (file.size > MAX_IMAGE_BYTES) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  // Ownership is checked with the visitor's own client, so the answer is what
  // row level security sees. The write below needs the secret key for Blob, and
  // it earns that only after this check came back with the game.
  const { data: owned } = await supabase
    .from("game")
    .select("id")
    .eq("shortname", shortname)
    .eq("owner_user_id", user.id)
    .maybeSingle();
  if (!owned) return;

  const bytes = new Uint8Array(await file.arrayBuffer());
  const header = readImageHeader(bytes.slice(0, IMAGE_HEADER_BYTES));
  if (!header) return;

  const ext = header.mime === "image/png" ? "png" : "webp";
  const path = `games/${shortname}/${kind}.${ext}`;

  const stored = await putBlobGameImage(path, bytes.buffer as ArrayBuffer, header.mime);
  if (!stored) return;

  // The hash is over the bytes, so a re-upload of the same picture is visible
  // as no change and a cache can key on it.
  const hash = await encodedHash(bytes.buffer as ArrayBuffer);

  const admin = createAdmin();
  if (!admin) return;
  const column = kind === "logo" ? "logo_path" : "banner_path";
  const hashColumn = kind === "logo" ? "logo_hash" : "banner_hash";
  await admin
    .from("game")
    .update({ [column]: stored, [hashColumn]: hash })
    .eq("id", owned.id);

  revalidatePath(`/games/${shortname}`);
}
