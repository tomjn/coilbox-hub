import { revalidateTag } from "next/cache";
import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import {
  GAME_FACTS_FORMAT,
  GAME_FACTS_MAX_BYTES,
  GAME_FACTS_VERSION,
  type GameFactsResponseBody,
  parseGameFactsBody,
} from "@/lib/api/gameFacts";
import { apiError } from "@/lib/api/response";
import { TAGS } from "@/lib/cache/tags";
import { buildGameSubmission, submitGameFacts } from "@/lib/games/submit";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * Where a game's facts arrive (#224). The hub never opens a game archive:
 * coilbox has them mounted, already reads their Lua through its unitsync
 * worker, and this is what it posts the result to.
 *
 * ## One game per request
 *
 * A map submission batches fifty maps because maps are independent. A game's
 * facts are not: the faction list is replaced as a set and the retirement pass
 * compares the batch against every unit the game has, so a slice of a game
 * would make `complete` mean whichever slice arrived last. One request carries
 * one shortname at one release, whole.
 *
 * ## What the hub works out for itself
 *
 * The digest over each unit's facts. A client sends measurements and never
 * conclusions, so it does not send digests either: an unknown field in any
 * entry is a refusal naming the field. The release string is stored verbatim
 * and never parsed, for the same reason a map's canonical name is never parsed.
 *
 * ## Why it needs a token
 *
 * Not to keep a secret. Every table this writes grants select to `anon`, so
 * everything here is public the moment it lands. The token is because this
 * writes, and `submitted_by` names an account: without somebody behind the
 * request there is nobody to attribute a backfill to and nothing to rate limit
 * against later.
 *
 * The consent to send facts at all is `hub.assetUploads`, coilbox's existing
 * switch, and the hub adds no second one, for the reason `/api/v1/maps` gives:
 * the hub cannot know whether a request was consented to, so the gate is the
 * client's to hold.
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

  // The declared length first, so a body far past the cap is refused before it
  // is buffered. It is a courtesy rather than the check: a header can be absent
  // or wrong, and what actually arrived is measured below.
  const declaredLength = Number(request.headers.get("content-length"));
  if (Number.isFinite(declaredLength) && declaredLength > GAME_FACTS_MAX_BYTES) {
    return apiError(`A request body may be at most ${GAME_FACTS_MAX_BYTES} bytes.`, 413);
  }

  let raw: string;
  try {
    raw = await request.text();
  } catch {
    return apiError("The request body could not be read.", 400);
  }

  // Measured in bytes rather than characters, because the cap is a byte cap.
  if (new TextEncoder().encode(raw).length > GAME_FACTS_MAX_BYTES) {
    return apiError(`A request body may be at most ${GAME_FACTS_MAX_BYTES} bytes.`, 413);
  }

  let body: unknown;
  try {
    body = JSON.parse(raw);
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  const parsed = parseGameFactsBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, parsed.status);
  }

  // The hub's own derivation, all of it before any database work: the digest
  // that decides whether each unit's facts are the ones already held.
  const submission = await buildGameSubmission(parsed.submission);

  // A deployment without the secret key cannot write at all, and without this
  // check the client gets Next's generic HTML 500 rather than a reason.
  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  const written = await submitGameFacts(admin, submission, auth.user.id);
  if (!written.ok) {
    return apiError("The game catalog could not be written just now.", 503);
  }

  // The games pages read through their own tag, so a backfill shows up on the
  // next request rather than whenever the cache feels like letting go.
  revalidateTag(TAGS.games, "max");

  const answer: GameFactsResponseBody = {
    format: GAME_FACTS_FORMAT,
    version: GAME_FACTS_VERSION,
    results: written.results,
  };
  return withCors(NextResponse.json(answer));
}
