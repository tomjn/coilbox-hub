"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { readImageHeader, IMAGE_HEADER_BYTES } from "@/lib/assets/imageHeader";
import { encodedHash } from "@/lib/assets/hash";
import { putStagedGameImage } from "@/lib/assets/staging";
import { TAGS } from "@/lib/cache/tags";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import type { GameLink } from "@/lib/games/catalog";
import { editableGame } from "@/lib/games/editor";
import {
  type GameImageUploadState,
  REMOVE_MESSAGES,
  refuseImageFile,
  UPLOAD_MESSAGES,
} from "@/lib/games/imageUpload";

/**
 * The writes behind ownership (#229): asking, deciding, and what an owner may
 * change once they hold the pen.
 *
 * Every action here is thin on purpose. The rules live in constraints and
 * policies - one open ask per person per game, nobody asking as somebody else,
 * only a moderator deciding, an owner or a moderator editing a game (#350) and
 * only the columns the grant names - and `supabase/tests/game_ownership.test.sql` proves
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

  // The owner and moderator policies filter out every row a stranger may not
  // change, so a stranger's edit succeeds over nothing. Returning the rows is
  // how the action knows whether it was the owner or a moderator writing, or a
  // passer-by.
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

export async function setGameVisibility(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  const hidden = form.get("hidden") === "true";
  if (!shortname) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const admin = createAdmin();
  if (!user || !admin) return;

  // A moderator or the game's owner (#242), asked with the visitor's own client.
  const game = await editableGame(supabase, user.id, shortname);
  if (!game) return;

  // The columns ride one update: who and when are part of the fact, and
  // unhiding clears both rather than leaving a stale name on a visible row.
  const { error } = await admin
    .from("game")
    .update(
      hidden
        ? { hidden_at: new Date().toISOString(), hidden_by: user.id }
        : { hidden_at: null, hidden_by: null },
    )
    .eq("id", game.id);
  if (error) return;

  revalidatePath("/games");
  revalidatePath(`/games/${shortname}`);
  revalidatePath("/moderation/games");
}

export async function setVersionVisibility(form: FormData): Promise<void> {
  const shortname = String(form.get("shortname") ?? "");
  const version = String(form.get("version") ?? "").slice(0, 64);
  const hidden = form.get("hidden") === "true";
  if (!shortname || !version) return;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const admin = createAdmin();
  if (!user || !admin) return;

  const game = await editableGame(supabase, user.id, shortname);
  if (!game) return;

  const { error } = await admin
    .from("game_version")
    .update(
      hidden
        ? { hidden_at: new Date().toISOString(), hidden_by: user.id }
        : { hidden_at: null, hidden_by: null },
    )
    .eq("game_id", game.id)
    .eq("version", version);
  if (error) return;

  revalidatePath(`/games/${shortname}`);
  revalidatePath("/moderation/games");
}

/**
 * A logo or banner from the edit page's form, answered with what the form
 * shows (#354). Every refusal says why, because a form that silently did
 * nothing is how an owner lost track of which pictures had saved.
 */
export async function uploadGameImage(
  _previous: GameImageUploadState | null,
  form: FormData,
): Promise<GameImageUploadState> {
  const shortname = String(form.get("shortname") ?? "");
  const kind = String(form.get("kind") ?? "");
  if (!shortname || (kind !== "logo" && kind !== "banner")) {
    return { ok: false, message: UPLOAD_MESSAGES.notSent };
  }

  const file = form.get("image");
  const refusal = refuseImageFile(file);
  if (refusal) return refusal;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, message: UPLOAD_MESSAGES.signedOut };

  // The owner or a moderator (#350), checked with the visitor's own client, so
  // the answer is what row level security sees. The write below needs the
  // secret key for the staging bucket, and it earns that only after this check
  // came back with the game.
  const owned = await editableGame(supabase, user.id, shortname);
  if (!owned) return { ok: false, message: UPLOAD_MESSAGES.notAllowed };

  // `refuseImageFile` has already refused anything that is not a file.
  const bytes = new Uint8Array(await (file as File).arrayBuffer());
  const header = readImageHeader(bytes.slice(0, IMAGE_HEADER_BYTES));
  if (!header) return { ok: false, message: UPLOAD_MESSAGES.wrongType };

  const ext = header.mime === "image/png" ? "png" : "webp";
  const path = `games/${shortname}/${kind}.${ext}`;

  const admin = createAdmin();
  if (!admin) {
    console.error(`uploadGameImage: no secret key, so ${path} was not stored`);
    return { ok: false, message: UPLOAD_MESSAGES.notSaved };
  }

  try {
    await putStagedGameImage(admin, path, bytes.buffer as ArrayBuffer, header.mime);
  } catch (error) {
    console.error(`uploadGameImage: the bucket refused ${path}`, error);
    return { ok: false, message: UPLOAD_MESSAGES.notSaved };
  }

  // The hash is over the bytes, so a re-upload of the same picture is visible
  // as no change and a cache can key on it.
  const hash = await encodedHash(bytes.buffer as ArrayBuffer);
  const column = kind === "logo" ? "logo_path" : "banner_path";
  const hashColumn = kind === "logo" ? "logo_hash" : "banner_hash";
  // Which store holds the staged copy, so promotion reads the bucket (#332).
  const stagedColumn = kind === "logo" ? "logo_staged_tier" : "banner_staged_tier";
  const { error } = await admin
    .from("game")
    .update({ [column]: path, [hashColumn]: hash, [stagedColumn]: "bucket" })
    .eq("id", owned.id);
  if (error) {
    console.error(`uploadGameImage: stored ${path} but the game row was not updated`, error);
    return { ok: false, message: UPLOAD_MESSAGES.notSaved };
  }

  // The tag as well as the two paths. The link preview is a route of its own
  // and reads the game row through the games tag.
  updateTag(TAGS.games);
  // The listing card draws the logo too, and names it by its hash (#345).
  revalidatePath("/games");
  revalidatePath(`/games/${shortname}`);

  return { ok: true, message: kind === "logo" ? "Logo uploaded." : "Banner uploaded." };
}

/**
 * Take a game's logo or banner off again (#360), answered the way an upload is.
 *
 * Only the row is written. Once it names no path, nothing links the staged
 * copy: the hub's art route refuses its hash, the daily sweep deletes it from
 * the bucket (`unclaimed_staged_objects`), and a promoted copy is deleted from
 * the durable tier by the next promotion run (`lib/assets/promoteGameImages.ts`).
 * Deleting the bucket object here instead would mean reserving it from a web
 * request, and a request that died holding the reservation would refuse the
 * next upload to that path until the sweep ran.
 */
export async function removeGameImage(
  _previous: GameImageUploadState | null,
  form: FormData,
): Promise<GameImageUploadState> {
  const shortname = String(form.get("shortname") ?? "");
  const kind = String(form.get("kind") ?? "");
  if (!shortname || (kind !== "logo" && kind !== "banner")) {
    return { ok: false, message: REMOVE_MESSAGES.notSent };
  }

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, message: REMOVE_MESSAGES.signedOut };

  // The same check an upload makes, for the same reason: the art columns are
  // not in the signed in grant, so the write needs the secret key, and it
  // earns that only after the visitor's own client finds the game editable.
  const owned = await editableGame(supabase, user.id, shortname);
  if (!owned) return { ok: false, message: REMOVE_MESSAGES.notAllowed };

  const admin = createAdmin();
  if (!admin) {
    console.error(`removeGameImage: no secret key, so the ${kind} of ${shortname} was not removed`);
    return { ok: false, message: REMOVE_MESSAGES.notSaved };
  }

  const { error } = await admin
    .from("game")
    .update({ [`${kind}_path`]: null, [`${kind}_hash`]: null, [`${kind}_staged_tier`]: null })
    .eq("id", owned.id);
  if (error) {
    console.error(`removeGameImage: the game row for ${shortname} would not clear its ${kind}`, error);
    return { ok: false, message: REMOVE_MESSAGES.notSaved };
  }

  // The tag as well as the two paths. The link preview is a route of its own
  // and reads the game row through the games tag.
  updateTag(TAGS.games);
  revalidatePath("/games");
  revalidatePath(`/games/${shortname}`);

  return { ok: true, message: kind === "logo" ? "Logo removed." : "Banner removed." };
}
