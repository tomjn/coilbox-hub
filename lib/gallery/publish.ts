import type { SupabaseClient } from "@supabase/supabase-js";
import {
  asContainer,
  type Container,
  decodeContainerText,
  GALLERY_KINDS,
  type GalleryKind,
  type GameIdentity,
  identify,
  makeContainer,
  MAX_CONTAINER_BYTES,
} from "@/lib/container";
import { ITEM_SUMMARY_COLUMNS, type ItemSummary } from "@/lib/gallery/query";

/**
 * Everything between a pasted share code and a row. The rules here are the app's
 * rules, not ours: anything accepted must be something coilbox will import, or
 * the gallery hands out links that fail on arrival.
 */

export interface AcceptedContainer {
  container: Container;
  kind: GalleryKind;
  kindVersion: number;
  gameName: string | null;
  /** The grouping key two items targeting the same game share (issue #50).
   * See {@link describe} for why this is not always the same value as
   * `gameName`, and sometimes has no value where `gameName` does. */
  gameKey: string | null;
  mapName: string | null;
}

export type AcceptResult =
  | { ok: true; accepted: AcceptedContainer }
  | { ok: false; reason: string };

function isGalleryKind(kind: string): kind is GalleryKind {
  return (GALLERY_KINDS as readonly string[]).includes(kind);
}

function str(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

/**
 * Pull out the map name a listing filters on. Each kind names it differently,
 * and only the shapes actually seen are handled. A kind whose payload has not
 * been looked at yields null rather than a guess at a field name that may not
 * exist, because a wrong map name is worse than no map name.
 *
 * The game identity is not derived here: `gameIdentityFromPayload` (via
 * `identify()`) already covers every kind, scenario included, from the one
 * `game` field every kind now writes, or its kind's old spelling when it does
 * not. Re-reading a kind's old spelling here would be the same duplication
 * this function used to be.
 *
 * Exported so scripts/backfill-game-names.ts can derive the same gameName and
 * gameKey for an already-stored row without a second copy of this logic.
 */
export function describe(
  kind: GalleryKind,
  payload: unknown,
  game: GameIdentity | undefined,
) {
  // gameName is what a person reads on a card: the stable shortname when
  // there is one, falling back to the exact pinned build so an item still
  // shows something rather than nothing.
  //
  // gameKey is what a listing groups and filters by, and it is deliberately
  // narrower (issue #50). Two items exported from different machines can
  // name the same game two different ways - one only has the shortname
  // (a challenge always does), one only has the exact archive name (an item
  // exported where the game was not installed, see gameIdentity.ts:22-25) -
  // and grouping the second under its archive name would mint a fresh facet
  // on every release, exactly the fragmentation issue #30 was filed to end.
  // So gameKey is only ever the shortname: stable across a game's releases,
  // and absent rather than guessed at when there isn't one. That leaves two
  // items that each carry only one spelling unable to group with each other,
  // which this does not attempt to fix - see the PR description for why.
  const gameName = game ? (game.shortname ?? game.name ?? null) : null;
  const gameKey = game?.shortname ?? null;

  if (typeof payload !== "object" || payload === null) {
    return { gameName, gameKey, mapName: null };
  }
  const p = payload as Record<string, unknown>;

  if (kind === "preset") {
    return { gameName, gameKey, mapName: str(p.mapName) };
  }

  if (kind === "setup-pack") {
    const maps = Array.isArray(p.maps) ? p.maps : [];
    return {
      gameName,
      gameKey,
      // A pack can carry several maps and the row holds one, so it is only
      // filled in when there is no ambiguity about which it would mean.
      mapName: maps.length === 1 ? str(maps[0]) : null,
    };
  }

  return { gameName, gameKey, mapName: null };
}

/**
 * Take what people actually have. Coilbox's share affordance produces a
 * `coilbox://import?code=…` link, not a bare code, so asking for a code and
 * refusing a link means refusing the only thing on their clipboard.
 *
 * Raw codes and raw JSON still work, because a saved export is JSON and someone
 * may well paste the contents of one.
 */
function unwrapShareLink(
  trimmed: string,
): { ok: true; code: string } | { ok: false; reason: string } {
  if (trimmed === "") {
    return { ok: false, reason: "Paste a share link or code first." };
  }
  if (!trimmed.toLowerCase().startsWith("coilbox://")) {
    return { ok: true, code: trimmed };
  }

  let link: URL;
  try {
    link = new URL(trimmed);
  } catch {
    return { ok: false, reason: "That coilbox link is malformed." };
  }

  const code = link.searchParams.get("code");
  if (code) return { ok: true, code };

  // The other two link shapes are real links that simply are not a thing to
  // publish, so they get told apart rather than lumped in with junk.
  if (link.searchParams.get("url")) {
    return {
      ok: false,
      reason:
        "That link points at a file hosted somewhere else. Paste the file's contents instead.",
    };
  }
  return {
    ok: false,
    reason: "That is a coilbox link, but it does not carry anything to publish.",
  };
}

/**
 * Decide whether a pasted link, code or uploaded file can be published, and pull
 * out what a listing needs. Never throws: an unusable input comes back with a
 * reason a person can act on rather than a validation code.
 */
export function accept(input: string): AcceptResult {
  const unwrapped = unwrapShareLink(input.trim());
  if (!unwrapped.ok) return unwrapped;
  const trimmed = unwrapped.code;

  const result = identify(trimmed);

  if (result.kind === "unknown") {
    return {
      ok: false,
      reason:
        "That is not something coilbox made. Use Share in the app and paste the link it copies.",
    };
  }

  if (result.compatibility === "newer") {
    return {
      ok: false,
      reason:
        "This came from a newer coilbox than the gallery understands. It cannot be published yet.",
    };
  }

  if (!isGalleryKind(result.kind)) {
    return {
      ok: false,
      reason: `The gallery does not carry ${result.kind}s yet.`,
    };
  }

  const decoded = decodeContainerText(trimmed);
  if (decoded === null) {
    return { ok: false, reason: "That share code could not be read." };
  }

  // identify() also recognises a legacy bare preset, which carries no envelope at
  // all. The gallery stores containers, so one gets wrapped in the envelope it
  // would have today rather than being turned away for its age.
  const container =
    asContainer(decoded) ??
    makeContainer(result.kind, result.version || 1, decoded);

  // The same ceiling the app enforces on import. Publishing anything larger would
  // mean handing out a link coilbox refuses to open.
  const size = new TextEncoder().encode(JSON.stringify(container)).byteLength;
  if (size > MAX_CONTAINER_BYTES) {
    return {
      ok: false,
      reason: "This is too large to share. Coilbox would refuse to import it.",
    };
  }

  return {
    ok: true,
    accepted: {
      container,
      kind: result.kind,
      kindVersion: container.kindVersion,
      ...describe(result.kind, container.payload, result.game),
    },
  };
}

export interface PublishFields {
  code: string;
  title: string;
  description: string;
  /** Raw, unnormalised tag text - split on commas already, but not yet
   * trimmed, lowercased, deduplicated of blanks or capped. That is a
   * publishing rule, not a transport detail, so it happens inside
   * `publishItem` rather than in each caller. */
  tags: string[];
}

export type PublishOutcome =
  | { ok: true; item: ItemSummary }
  | {
      ok: false;
      reason: string;
      /** What kind of failure this was, for a caller that needs to turn it
       * into something other than a message - the API route maps this to a
       * status code, the form does not need it at all. */
      status: "invalid" | "rate_limited" | "storage_error";
    };

/**
 * Everything between validated fields and a stored row: run the code through
 * `accept()`, check the title, normalise the tags, and insert. This is the
 * one path that can turn a share code into a row, shared by the publish form
 * (`app/publish/actions.ts`) and `POST /api/v1/items` (issue 25), so there is
 * exactly one place that decides what the database will accept. A second
 * path that skipped or reimplemented this is the failure issue 25 exists to
 * prevent.
 *
 * The caller has already established who is publishing - the form from the
 * session cookie, the API from a bearer token - and hands in a client whose
 * row level security runs as that user, plus that user's id for `author_id`.
 * This function never checks who is signed in itself.
 */
export async function publishItem(
  supabase: SupabaseClient,
  authorId: string,
  fields: PublishFields,
): Promise<PublishOutcome> {
  const result = accept(fields.code);
  if (!result.ok) {
    return { ok: false, reason: result.reason, status: "invalid" };
  }

  const title = fields.title.trim();
  if (title === "") {
    return {
      ok: false,
      reason: "Give it a title so people know what it is.",
      status: "invalid",
    };
  }

  const tags = fields.tags
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
      description: fields.description.trim(),
      game_name: accepted.gameName,
      game_key: accepted.gameKey,
      map_name: accepted.mapName,
      tags,
      container: accepted.container,
      author_id: authorId,
    })
    .select(ITEM_SUMMARY_COLUMNS)
    .single();

  if (error) {
    // 53400 is the rate limit trigger's errcode (see
    // supabase/migrations/20260809181909_publish_rate_limit.sql). PostgREST
    // passes an unrecognised SQLSTATE through as the error code verbatim, so
    // this is the only way to tell "too many" apart from any other write
    // failure without duplicating the threshold here.
    const status = error.code === "53400" ? "rate_limited" : "storage_error";
    return { ok: false, reason: `Could not publish it: ${error.message}`, status };
  }

  return { ok: true, item: data as unknown as ItemSummary };
}
