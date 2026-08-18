import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import {
  buildMapSubmitBody,
  MAP_SUBMIT_MAX_BYTES,
  type MapSubmitResult,
  parseMapSubmitBody,
  type SubmittedEntry,
} from "@/lib/api/mapSubmit";
import { apiError } from "@/lib/api/response";
import { buildSubmission, type MapOutcome, submitMapFacts } from "@/lib/maps/submit";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * Where a map's facts arrive (#187). The hub never opens an archive: coilbox
 * already has them mounted, already reads their Lua, their SMF header and their
 * infomaps, and this is what it posts the result to.
 *
 * The pictures of a map come through `/api/v1/assets/upload` and this is not
 * that route. That one carries bytes, so it takes one asset per request against
 * a platform body cap and a Blob write that succeeds or fails whole. There are
 * no bytes here, so fifty maps travel together and each gets its own outcome
 * inside a 200.
 *
 * ## What the hub works out for itself
 *
 * The slug, the facts digest and the author keys. A client sends measurements
 * and never conclusions, so it does not send tags either and nothing here asks
 * for them: `public.map_listing` decides what kind of map it is from what was
 * measured, and a claim from a client would be a claim the hub would not
 * believe.
 *
 * ## Why there is no ownership rule
 *
 * `lib/assets/upload.ts:446` refuses a replacement from anyone but the original
 * uploader, and that rule is right for what it protects: a replacement puts an
 * approved picture back into the moderation queue, so a stranger swapping bytes
 * takes reviewed content off the site.
 *
 * Facts have no such exposure. They are reproducible, so two honest clients
 * reading one archive produce identical rows and a replacement changes nothing
 * worth defending. An owner would mean a map's facts are frozen to whoever
 * installed it first, and a later improvement to extraction could never reach
 * it.
 *
 * ## Why it needs a token
 *
 * Not to keep a secret. `public.map` grants select to `anon`, so everything
 * written here is public the moment it lands. The token is because this writes:
 * `submitted_by` names an account, the rate limit counts per account, and a
 * conflict record says who reported it. None of those means anything without
 * somebody behind the request.
 *
 * The consent to send facts at all is `hub.assetUploads`, coilbox's existing
 * switch, and the hub adds no second one, for the reason `/api/v1/maps/have`
 * gives: the hub cannot know whether a request was consented to, so the gate is
 * the client's to hold.
 */
export const dynamic = "force-dynamic";

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
  if (Number.isFinite(declaredLength) && declaredLength > MAP_SUBMIT_MAX_BYTES) {
    return apiError(`A request body may be at most ${MAP_SUBMIT_MAX_BYTES} bytes.`, 413);
  }

  let raw: string;
  try {
    raw = await request.text();
  } catch {
    return apiError("The request body could not be read.", 400);
  }

  // Measured in bytes rather than characters, because the cap is a byte cap and
  // a map description in a script outside Latin-1 is several bytes a character.
  if (new TextEncoder().encode(raw).length > MAP_SUBMIT_MAX_BYTES) {
    return apiError(`A request body may be at most ${MAP_SUBMIT_MAX_BYTES} bytes.`, 413);
  }

  let body: unknown;
  try {
    body = JSON.parse(raw);
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  const parsed = parseMapSubmitBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, parsed.status);
  }

  // The hub's own three: the slug a map lives at, the slug it takes if another
  // map already holds that one, and the digest that decides whether these facts
  // are the facts already stored. All of them before any database work, because
  // none of them can fail and the function needs all three.
  const submissions = await Promise.all(
    parsed.entries.flatMap((entry) => (entry.ok ? [buildSubmission(entry.entry)] : [])),
  );

  // A batch where every entry was malformed has nothing to write, so it never
  // reaches the database and never spends a request against the rate limit. The
  // client still gets a refusal per map saying what was wrong with it.
  if (submissions.length === 0) {
    return answer(parsed.entries, new Map());
  }

  // A deployment without the secret key cannot write at all, and the proxy's
  // configuration check only covers the two public variables. Without this the
  // client gets Next's generic HTML 500, which is the failure issue 54 was
  // about.
  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  const written = await submitMapFacts(admin, submissions, auth.user.id);
  if (!written.ok) {
    return written.rateLimited
      ? apiError("Too many maps submitted in the last hour. Try again later.", 429)
      : apiError("The map catalog could not be written just now.", 503);
  }

  return answer(parsed.entries, written.outcomes);
}

/**
 * The reply: one result per map, in the order the request listed them.
 *
 * Two sources merge here. An entry the parser refused never reached the database
 * and carries its own reason, and everything else carries what the submission
 * function decided. The order is the request's, which is what lets a client zip
 * the results against the maps it sent.
 *
 * A map the function answered nothing for is a hub bug rather than an outcome,
 * so the whole request fails rather than inventing a word for it. A client that
 * was told `stored` about a map that was not stored would never send it again.
 */
function answer(entries: SubmittedEntry[], outcomes: Map<string, MapOutcome>) {
  const results: MapSubmitResult[] = [];

  for (const entry of entries) {
    if (!entry.ok) {
      results.push({ map_name: entry.mapName, outcome: "refused", said: entry.said });
      continue;
    }

    const decided = outcomes.get(entry.entry.map_name);
    if (!decided) {
      return apiError("The map catalog could not be written just now.", 503);
    }

    results.push(
      decided.said
        ? { map_name: entry.entry.map_name, outcome: decided.outcome, said: decided.said }
        : { map_name: entry.entry.map_name, outcome: decided.outcome },
    );
  }

  // Built here rather than through `apiJson`, which adds the sixty second cache
  // header the gallery routes carry. This is the answer to one write and holding
  // it would serve a second client the first one's outcomes. Nothing may hold
  // it.
  return withCors(
    NextResponse.json(buildMapSubmitBody(results), {
      headers: { "Cache-Control": "no-store" },
    }),
  );
}
