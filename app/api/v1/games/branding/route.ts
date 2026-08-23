import { revalidateTag } from "next/cache";
import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import {
  GAME_BRANDING_FORMAT,
  GAME_BRANDING_MAX_BYTES,
  GAME_BRANDING_VERSION,
  type GameBrandingResponseBody,
  parseGameBrandingFields,
} from "@/lib/api/gameBranding";
import { apiError } from "@/lib/api/response";
import { TAGS } from "@/lib/cache/tags";
import { BLOB_TOKEN_ERROR, putBlobGameImage } from "@/lib/assets/blob";
import { encodedHash } from "@/lib/assets/hash";
import { readImageHeader } from "@/lib/assets/imageHeader";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * Where a game's own art arrives (#285): one logo or banner per request, from
 * an account the game row names as its owner.
 *
 * This is the API twin of `uploadGameImage` (`app/games/actions.ts`), which is
 * the same write for a browser form. Both exist because the branding catalog
 * coilbox reads is community-curated, and curated art is exactly what the hub
 * wants on a game row - but a catalog edit reaches users only when somebody
 * runs the import, while this door lets the game's own people keep their art
 * current without anybody else in the loop.
 *
 * ## Why ownership and not the facts route's open door
 *
 * `/api/v1/games/facts` takes facts from any signed-in account because facts
 * are measurements off an archive: wrong ones are wrong data. A picture on a
 * game row is a voice, and #229 settled that voices belong to owners - the
 * owner update policy writes everything about a game except its identity,
 * images and ownership, and images get their own gate rather than none. The
 * web action checks the same thing through the visitor's own client.
 *
 * ## Why it needs no moderation queue
 *
 * Because the gate is the moderation. An upload route cannot work that way -
 * its uploads arrive unreviewed by construction - but an owner choosing their
 * own game's face is the relationship the whole ownership migration was built
 * to express, and holding that behind a review queue would say owners are not
 * trusted with what the policy already lets them write.
 */
export const OPTIONS = corsPreflight;

export async function POST(request: Request) {
  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    return apiError(
      'The request body must be multipart/form-data with "shortname", "kind" and "file" parts.',
      415,
    );
  }

  const parsed = parseGameBrandingFields(form);
  if (!parsed.ok) {
    return apiError(parsed.error, 400);
  }

  const file = form.get("file");
  if (!(file instanceof Blob)) {
    return apiError('The "file" part is required and must carry the encoded bytes.', 400);
  }
  if (file.size > GAME_BRANDING_MAX_BYTES) {
    return apiError(`A picture may be at most ${GAME_BRANDING_MAX_BYTES} bytes.`, 413);
  }
  if (file.size === 0) {
    return apiError('The "file" part is empty.', 400);
  }

  const bytes = await file.arrayBuffer();

  // What the bytes actually are, out of the header rather than out of anything
  // declared (#105). These pictures go straight onto a public game row with no
  // reviewer between them and a page, so the shallow parse is the whole of the
  // checking they get - which is why it reads the full body rather than a
  // header window: an extended WebP may park its image chunk past 4 KB, and a
  // picture refused for where its own metadata sits is a refusal nobody can
  // act on.
  const image = readImageHeader(new Uint8Array(bytes));
  if (!image) {
    return apiError('The "file" part must be a PNG or WebP the hub can measure.', 400);
  }

  const path = `games/${parsed.shortname}/${parsed.kind}.${image.mime === "image/png" ? "png" : "webp"}`;

  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  // Ownership through the visitor's own client, so row level security answers
  // rather than a service role that bypasses it. The secret key below earns its
  // place only after this check came back with the game.
  const { data: owned } = await auth.supabase
    .from("game")
    .select("id")
    .eq("shortname", parsed.shortname)
    .eq("owner_user_id", auth.user.id)
    .maybeSingle();

  if (!owned) {
    return apiError("Only the account the game row names as its owner may send its art.", 403);
  }

  // The hash over the encoded bytes decides whether anything changes at all,
  // before the store is asked for anything: a repeat of the bytes already on
  // the row costs no advanced operation, which matters on a Hobby tier.
  const hash = await encodedHash(bytes);

  const hashColumn = parsed.kind === "logo" ? "logo_hash" : "banner_hash";
  const pathColumn = parsed.kind === "logo" ? "logo_path" : "banner_path";
  const { data: current } = await admin
    .from("game")
    .select(`${pathColumn},${hashColumn}`)
    .eq("id", owned.id)
    .maybeSingle();

  const held = current as Record<string, unknown> | null;
  if (held && held[hashColumn] === hash && held[pathColumn] !== null) {
    const answer: GameBrandingResponseBody = {
      format: GAME_BRANDING_FORMAT,
      version: GAME_BRANDING_VERSION,
      outcome: "unchanged",
      kind: parsed.kind,
    };
    return withCors(
      NextResponse.json(answer, { status: 200, headers: { "Cache-Control": "no-store" } }),
    );
  }

  let stored: string | null;
  try {
    stored = await putBlobGameImage(path, bytes, image.mime);
  } catch (error) {
    if (error instanceof Error && error.message === BLOB_TOKEN_ERROR) {
      return apiError(BLOB_TOKEN_ERROR, 503);
    }
    return apiError("The asset store would not accept that upload just now.", 502);
  }
  if (!stored) {
    return apiError("The asset store would not accept that upload just now.", 502);
  }

  const { error: writeError } = await admin
    .from("game")
    .update({ [pathColumn]: stored, [hashColumn]: hash })
    .eq("id", owned.id);

  if (writeError) {
    return apiError("The picture was uploaded but the game row could not be written.", 503);
  }

  // The games pages read through their own tag, so new art shows up on the
  // next request rather than whenever the cache feels like letting go.
  revalidateTag(TAGS.games, "max");

  const answer: GameBrandingResponseBody = {
    format: GAME_BRANDING_FORMAT,
    version: GAME_BRANDING_VERSION,
    outcome: "stored",
    kind: parsed.kind,
  };
  return withCors(
    NextResponse.json(answer, { status: 200, headers: { "Cache-Control": "no-store" } }),
  );
}
