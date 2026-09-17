import type { SupabaseClient } from "@supabase/supabase-js";
import { type AssetIdentity, type AssetOrigin, UNIT_RENDER_VARIANT_PREFIX } from "./asset";
import { capForVariant } from "./caps";
import { identityFilter } from "./have";
import { ASSET_MIME_EXTENSIONS, assetObjectPath, isAssetMime } from "./path";
import { type SourceConflict, sourceConflict } from "./sourceConflict";
import vocabulary from "./vendor/asset-vocabulary.json";

/**
 * Everything an upload is refused for, in one place (issue #104).
 *
 * ## Why this is a module and not a route
 *
 * It was written for two upload paths, so that a check could not exist on one
 * and not the other. There is one now: everything posts the bytes to
 * `POST /api/v1/assets/upload` and the route writes the bytes to the staging
 * bucket. The client direct path went in #133, because the browser SDK hands
 * the uploader the finished URL of its own unreviewed picture.
 *
 * It stays a module anyway. The route reads as a sequence of refusals with the
 * write at the end, and {@link checkAssetUpload} is where the reason for each
 * refusal and its cost live. #107 owns its numbers.
 *
 * ## Why every check is before the write
 *
 * The rule dates from Vercel Blob, where every `put()` spent one of 2,000
 * advanced operations a month and going over suspended the store for 30 days,
 * which happened in September 2026. The bucket (#332) has no operation
 * allowance, but it has 1 GB, and a rejected upload should still cost nothing
 * at all. So nothing here may run after the write and the checks are ordered
 * cheapest first: everything answerable from the request alone before anything
 * that asks the database.
 *
 * The database checks are issued together and read in a fixed order afterwards.
 * That keeps one round trip's latency while keeping the answer deterministic,
 * so a request that trips two limits always hears about the same one.
 *
 * ## What is not here
 *
 * The licence, and its absence is deliberate (#167). `asset_licence` used to
 * decide whether an upload was allowed, and because a subject with no row reads
 * as `unknown`, it refused everything nobody had researched. Splinter Faction,
 * which has one of the most permissive licences in the corpus, was refused with
 * a 403. Licences are not a gate on upload: moderation and reporting are how a
 * picture that should not be published is dealt with, and they look at the
 * picture rather than at a table nobody has filled in. The table stays as
 * recorded research for a moderator to read, and nothing here consults it.
 *
 * The per class caps (#105) are in `./caps`, and the route applies them between
 * the last pure check and the first database one. They need the bytes and this
 * module never sees them, and they need no round trip, so putting them here
 * would only move a check the request can answer on its own behind one that
 * costs a query. The per class byte ceiling is the exception and is read here,
 * because a byte count is the one thing about a picture the declaration carries.
 */

/**
 * The largest object the hub will take from a class that has no number of its
 * own, well under the 4.5 MB the platform refuses a function body at.
 *
 * The platform limit is free enforcement that runs before any code here does,
 * so this number is not about protecting the function. It is about what a game
 * asset plausibly is: buildpics are 5 to 10 KB and minimaps and renders 40 to
 * 150 KB, so 2 MB is more than an order of magnitude of headroom and anything
 * over it is not the thing it claims to be.
 *
 * It is a backstop rather than the cap most uploads meet. #107 asks for the cap
 * before anything is written and a class whose longest edge is fixed says what
 * its bytes may be far more tightly than this does, so buildpics, renders and
 * minimaps are held to `maxBytes` in `./caps` and reach this number never.
 *
 * Two classes still take it, and they are `overlay:metal` and `overlay:type`.
 * They are 8 bit and heavily quantised, nothing measured says they come near
 * this, and nothing measured says where their grid is either.
 *
 * `overlay:height` used to be the awkward one. It was 16 bit at the map's own
 * resolution, so a large map's ran to four megabytes and this number refused
 * seven of the ninety seven maps in the collection, which is why #142 gave it a
 * cap off the declared map size. It is a 512px 8 bit picture now
 * (tomjn/coilbox#1730), so it takes the ordinary derivation like every other
 * capped class and the special case has gone with it.
 */
export const ASSET_MAX_OBJECT_BYTES = vocabulary.maxObjectBytes;

/**
 * How much of the store one account may hold.
 *
 * The staging bucket is 1 GB, so this is a sixteenth of it. It sums every row
 * the account ever uploaded, promoted ones included. It is a ceiling on one
 * account taking the store away from everybody else rather than a budget
 * anybody is expected to reach: the entire buildpic corpus is about 20 MB.
 */
export const ACCOUNT_STORAGE_QUOTA_BYTES = 64 * 1024 * 1024;

/**
 * How many stored renders any one `(game, unit_name)` may have.
 *
 * The cap #107 calls the one that matters most, and the reason it gives is
 * specific to renders: buildpics are negligible at about 20 MB for the whole
 * corpus and the map set is fixed at around 3,575, so renders are the only class
 * that scales without a bound in the data, at units times angles. Eight angles
 * is already more than any use case has asked for, and the point is that nothing
 * can bulk render a roster.
 *
 * On renders rather than on variants, which is the correction #107 asks for.
 * Counting every variant made this refuse the wrong upload: a unit holding eight
 * renders would turn away its buildpic, the one picture every unit wants and the
 * class the issue calls negligible. Nothing needs a second cap over the rest,
 * because a unit's only other variant is the buildpic and its identity index
 * already holds it to one.
 */
export const UNIT_RENDER_CEILING = 8;

/**
 * How many assets one account may upload for one subject in an hour, where the
 * subject is a game for a unit asset and maps as a whole for a map asset.
 *
 * Backfill is meant to be lazy: the units a viewed blueprint actually
 * references, not the roster. This is insurance against a client looping, in
 * the spirit of `enforce_publish_rate_limit` on `public.item`. It was 100, and
 * one account's backfill on 2026-09-16 reached 80 an hour on each of five games.
 * Uploads go to the bucket, which has no operation allowance, and the byte caps
 * and {@link ACCOUNT_STORAGE_QUOTA_BYTES} bound what a loop can store, so this
 * only has to catch a loop and must stay well clear of a real backfill.
 *
 * Counted on `seen_at`, because a replacement (#106) writes a new object without
 * creating a row, so `created_at` would read a client looping on replacements
 * as no uploads at all. `updated_at` is wrong in the other direction, since
 * approving a row in the moderation grid touches it.
 *
 * One rule with a subject rather than two rules, because a map asset is not
 * scoped to a game and inventing a game for it would either exempt maps or
 * force a second limit that drifts from this one.
 */
export const SUBJECT_UPLOADS_PER_HOUR = 500;

/**
 * What a client says it is uploading. Every field here ends up on the row
 * except the ones the hub decides for itself: `path`, `tier`, `moderation`,
 * `approval_source` and `uploaded_by`.
 *
 * `width` and `height` are not here, and their absence is #105's answer. The
 * hub measures the image header, so a declared pair could only agree with the
 * bytes or be wrong, and there is no third thing a client could usefully mean
 * by it. {@link writeUploadedAsset} takes the measured pair separately.
 *
 * `hash` is not here either, and its absence is #154's answer. It is over the
 * encoded bytes, which arrive in the same request, so the hub computes it in
 * `./hash` and both functions below take it separately for the same reason the
 * measured pair is separate: a value the hub worked out does not belong in the
 * type named for what a client claimed. `source_hash` does stay, because the
 * hub never sees an archive and so has nothing to check it against.
 */
export interface AssetUploadDeclaration {
  identity: AssetIdentity;
  /** Over the raw archive bytes. Identity, and what the have check compares.
   * Unverifiable here and deliberately trusted: the archive never reaches the
   * hub, and this names no object and decides no path. */
  sourceHash: string;
  encodeProfile: string;
  origin: AssetOrigin;
  mime: string;
  bytes: number;
  /** The map in world units, set on a map row and null on a unit row. */
  mapWidth: number | null;
  mapHeight: number | null;
  /** The elmo range a height overlay's ramp spans, and null on everything else.
   * Only the archive has these and nothing downstream can recover them. */
  worldHeightMin: number | null;
  worldHeightMax: number | null;
  sourceArchive: string;
}

/** What the bytes turned out to be, from `./caps`. Never what a client said. */
export interface AssetImageDimensions {
  width: number;
  height: number;
}

/**
 * Present on either answer, and set only when this upload reports different
 * source bytes for an archive the hub already holds bytes for (#116).
 *
 * On both, because the case the issue is about is a *refused* upload: a second
 * account cannot replace the row, so the disagreement arrives attached to a 409
 * and would otherwise leave no trace at all. It is a note for the caller to
 * record and never a reason for either answer. See `./sourceConflict`.
 */
export type AssetUploadCheck =
  | {
      ok: true;
      path: string;
      /** The row a newer archive is replacing, or null on a first upload. */
      replacing: string | null;
      conflict?: SourceConflict;
    }
  | { ok: false; error: string; status: number; conflict?: SourceConflict };

/** What the identity check needs off a row that already exists. Each column is
 * a question a later check asks: who may replace it, whether these are the same
 * source bytes, which archive those bytes came out of, whether it is in a state
 * that may be replaced at all, how much of the account's quota the superseded
 * row is holding, and whether the hub has lost its bytes (#336). */
const EXISTING_COLUMNS =
  "id, source_hash, source_archive, uploaded_by, moderation, bytes, bytes_missing_at";

interface ExistingAsset {
  id: string;
  source_hash: string;
  source_archive: string;
  uploaded_by: string | null;
  moderation: string;
  bytes: number;
  bytes_missing_at: string | null;
}

/**
 * A count, or null when the query failed. Null is not zero: reading a failed
 * quota query as an empty one would let every limit through at exactly the
 * moment the database is unwell.
 */
async function countRows(
  query: PromiseLike<{ count: number | null; error: unknown }>,
): Promise<number | null> {
  const { count, error } = await query;
  return error ? null : (count ?? 0);
}

/**
 * The row this identity already has, or null when it has none.
 *
 * `maybeSingle` rather than a list, because both identity indexes are unique
 * and partial, so an identity matches at most one row and a second one would be
 * a bug worth hearing about rather than a row to pick from.
 *
 * This used to be a count, which was enough while an existing identity was only
 * ever a refusal. A replacement has to know who owns the row, what source bytes
 * it already holds and what state it is in, so it reads the row.
 */
async function fetchExisting(
  supabase: SupabaseClient,
  identity: AssetIdentity,
): Promise<{ ok: true; row: ExistingAsset | null } | { ok: false }> {
  const { data, error } = await supabase
    .from("asset")
    .select(EXISTING_COLUMNS)
    .or(identityFilter(identity))
    .maybeSingle();

  return error ? { ok: false } : { ok: true, row: data as ExistingAsset | null };
}

const QUOTA_UNAVAILABLE = {
  ok: false,
  error: "The upload quotas could not be read just now. Try again shortly.",
  status: 503,
} as const;

/**
 * Everything that has to be true before a byte is written, and the path the
 * bytes go to when it all is.
 *
 * `supabase` must be the secret key client. Every question here is about rows
 * `asset_read_approved` hides, and asking through the publishable key would
 * read a pending upload as absent and a full store as empty.
 *
 * `hash` is the hub's own, out of `./hash`, and is a parameter rather than a
 * field on the declaration so that there is no declared value here to reach for
 * by mistake. It is the leaf of the path this answers with (#154).
 */
export async function checkAssetUpload(
  supabase: SupabaseClient,
  userId: string,
  declaration: AssetUploadDeclaration,
  hash: string,
): Promise<AssetUploadCheck> {
  const { identity, mime, bytes } = declaration;

  // Pure checks first. None of these costs a round trip, so the request that
  // was never going to be accepted is refused before the hub does any work.
  if (!isAssetMime(mime)) {
    return {
      ok: false,
      error: `\`mime\` must be one of ${Object.keys(ASSET_MIME_EXTENSIONS).join(", ")}.`,
      status: 415,
    };
  }

  // Two sources, in order of how much each knows about the picture. The class's
  // own number where the class fixes a longest edge, and the global backstop
  // where it does not. A null from both is a variant the hub stores nothing for,
  // which `checkAssetImage` has already refused and which the path check below
  // refuses again, so it takes the backstop rather than an exemption.
  const maxBytes = capForVariant(identity.variant)?.maxBytes ?? ASSET_MAX_OBJECT_BYTES;
  if (bytes > maxBytes) {
    return {
      ok: false,
      error: `A "${identity.variant}" may be at most ${maxBytes} bytes. That one declares ${bytes}.`,
      status: 413,
    };
  }

  // The hash is the hub's, so the only part of this that can fail now is the
  // identity. It is still asked, because `assetObjectPath` answers for both.
  const path = assetObjectPath(identity, hash, mime);
  if (!path) {
    return {
      ok: false,
      error:
        "`game` and `variant` have to be storable as path segments: letters, digits, dots, dashes and underscores, and a variant may separate segments with a colon.",
      status: 400,
    };
  }

  // One round trip for the rest. Read in a fixed order below, so a request that
  // trips two limits always hears about the same one.
  const since = new Date(Date.now() - 60 * 60 * 1000).toISOString();

  const recentForSubject =
    identity.keyedOn === "unit"
      ? supabase
          .from("asset")
          .select("id", { count: "exact", head: true })
          .eq("uploaded_by", userId)
          .eq("game", identity.game)
          .gte("seen_at", since)
      : supabase
          .from("asset")
          .select("id", { count: "exact", head: true })
          .eq("uploaded_by", userId)
          .not("map_name", "is", null)
          .gte("seen_at", since);

  const [existing, unitRenders, accountBytes, recent] =
    await Promise.all([
      fetchExisting(supabase, identity),
      identity.keyedOn === "unit" && identity.variant.startsWith(UNIT_RENDER_VARIANT_PREFIX)
        ? countRows(
            supabase
              .from("asset")
              .select("id", { count: "exact", head: true })
              .eq("game", identity.game)
              .eq("unit_name", identity.unitName)
              .like("variant", `${UNIT_RENDER_VARIANT_PREFIX}%`),
          )
        : Promise.resolve(0),
      supabase.rpc("account_asset_bytes", { account: userId }),
      countRows(recentForSubject),
    ]);

  if (!existing.ok) return QUOTA_UNAVAILABLE;
  const replacing = existing.row;

  // Worked out before any of the refusals below, because the two outcomes it
  // rides along on are on opposite sides of them: the identity belonging to
  // somebody else, and the upload being accepted.
  //
  // Deliberately not carried by the refusals in between. A conflicting upload
  // that then trips the render ceiling or an hourly limit is a client that will
  // try again, and recording a disagreement about bytes the hub declined to
  // take on capacity grounds would mark a tile over a queue length.
  const conflict = replacing
    ? (sourceConflict(replacing, declaration, userId) ?? undefined)
    : undefined;

  if (replacing) {
    // Only the account that uploaded it. The alternative, anyone may replace
    // anyone's asset, hands every signed in account a way to take the whole
    // corpus off the site: a replacement resets the row to pending, so one
    // account could de-publish every approved picture it can name and leave a
    // moderator to re-review the lot. The rate limits bound how fast that goes
    // and not whether it works. A seeded row has a null `uploaded_by` and is
    // nobody's to replace, which is the same rule and not an extra one.
    //
    // The cost is that a newer archive held by somebody else cannot refresh a
    // picture, so the corpus can go stale. That is the lesser harm and the
    // fixable one: replacing across accounts wants a capability of the kind
    // #101 already has, and #138 is where it goes.
    //
    // This refusal is also the whole of #116's interesting case, which is why
    // it carries the conflict. "A second user reports different source bytes
    // from the same archive" is exactly a stranger's replacement, so the rule
    // above already stops it dead and, until now, stopped it silently. The
    // upload is still refused, unchanged. What the note adds is that the
    // picture the hub is keeping gets marked, so somebody looks at it.
    if (replacing.uploaded_by !== userId) {
      return {
        ok: false,
        error: "Another account uploaded the asset with that identity, so it cannot be replaced.",
        status: 409,
        conflict,
      };
    }

    // A rejection is a state and never a delete (#115), and a safety rejection
    // is not overridable. Letting a replacement put the row back to pending
    // would make it overridable by anybody with different bytes and the same
    // identity, which is the whole control undone in one request.
    //
    // Every rejected row, not only the safety ones, because an editorial
    // rejection is a moderator's call about whether a picture belongs and an
    // upload is not the way to argue with it. `public.return_asset` is, and it
    // is a moderator's to call. The table refuses the safety half underneath
    // this regardless of what any route does.
    if (replacing.moderation === "rejected") {
      return {
        ok: false,
        error: "That asset was rejected, and a rejection is not something an upload can undo.",
        status: 409,
      };
    }

    // The same source bytes the hub already holds. Storing them again would
    // spend an advanced operation to end up where it started and, worse, reset
    // an approved row to pending, so a client retrying in a loop would keep its
    // own picture out of the gallery. `/api/v1/assets/have` answers this for
    // free and in batches, which is what a well behaved client asks first.
    //
    // Not when the hub has lost the bytes (#336). The row is still there, but
    // the store it names will not return them, so the same source bytes are
    // exactly what is wanted and `have` has already said so.
    if (replacing.source_hash === declaration.sourceHash && replacing.bytes_missing_at === null) {
      return {
        ok: false,
        error:
          "The hub already holds that asset with the same `source_hash`. Check `/api/v1/assets/have` before uploading.",
        status: 409,
      };
    }
  }

  if (unitRenders === null) return QUOTA_UNAVAILABLE;
  // Measured on what the table will hold afterwards, which is one more on a
  // first upload and the same number on a replacement. A replacement stores no
  // new render, so the ceiling has nothing to refuse. Anything that is not a
  // render counted zero above and cannot trip this.
  if (unitRenders + (replacing ? 0 : 1) > UNIT_RENDER_CEILING) {
    return {
      ok: false,
      error: `That unit already has ${unitRenders} stored renders, which is the ceiling of ${UNIT_RENDER_CEILING}.`,
      status: 409,
    };
  }

  if (accountBytes.error || typeof accountBytes.data !== "number") return QUOTA_UNAVAILABLE;
  // The superseded row's bytes come back off the total, because the quota is
  // measured over rows and the replacement leaves one row where there was one.
  // The superseded object outlives the row it belonged to, but that is an
  // orphan for #113 to clear rather than storage this account still holds.
  if (accountBytes.data - (replacing?.bytes ?? 0) + bytes > ACCOUNT_STORAGE_QUOTA_BYTES) {
    return {
      ok: false,
      error: `That upload would put this account over its ${ACCOUNT_STORAGE_QUOTA_BYTES} byte storage quota.`,
      status: 413,
    };
  }

  if (recent === null) return QUOTA_UNAVAILABLE;
  if (recent >= SUBJECT_UPLOADS_PER_HOUR) {
    return {
      ok: false,
      error: `Too many uploads for that subject in the last hour, which is capped at ${SUBJECT_UPLOADS_PER_HOUR}. Try again later.`,
      status: 429,
    };
  }

  return {
    ok: true,
    path,
    replacing: replacing?.id ?? null,
    ...(conflict ? { conflict } : {}),
  };
}

/** Everything on the row that describes the bytes rather than the identity, and
 * therefore everything a newer archive changes. */
function assetColumns(
  declaration: AssetUploadDeclaration,
  hash: string,
  path: string,
  measured: AssetImageDimensions,
) {
  return {
    source_hash: declaration.sourceHash,
    hash,
    encode_profile: declaration.encodeProfile,
    path,
    origin: declaration.origin,
    tier: "bucket",
    mime: declaration.mime,
    bytes: declaration.bytes,
    width: measured.width,
    height: measured.height,
    map_width: declaration.mapWidth,
    map_height: declaration.mapHeight,
    world_height_min: declaration.worldHeightMin,
    world_height_max: declaration.worldHeightMax,
    source_archive: declaration.sourceArchive,
  };
}

/**
 * Whether the account uploading may skip the moderation queue.
 *
 * `supabase` must be the uploader's own client, the one built from their bearer
 * token, because `has_capability()` answers for the caller and nobody else.
 * Asked with the secret key it would answer for no one.
 *
 * Two capabilities, and holding either is enough:
 *
 * - `can_publish_unreviewed`, which is exactly this.
 * - `can_moderate`. A moderator can approve any picture in the grid, their own
 *   included, so sending theirs through the queue only makes them do it by
 *   hand. Skipping it waives nothing they could not already do. It is still
 *   recorded as a bypass rather than a moderator approval, because nobody
 *   looked at these bytes in the grid, and `asset_event` has to be able to say
 *   so.
 *
 * False when the question could not be answered. The safe way to be wrong is a
 * picture that waits in the queue.
 */
export async function uploaderSkipsQueue(supabase: SupabaseClient): Promise<boolean> {
  const answers = await Promise.all(
    (["can_publish_unreviewed", "can_moderate"] as const).map((capability) =>
      supabase.rpc("has_capability", { capability }).then(
        ({ data, error }) => !error && data === true,
        () => false,
      ),
    ),
  );

  return answers.some(Boolean);
}

/**
 * Write the row for an upload the hub has just accepted, replacing the row named
 * by `replacing` when a newer archive changed the bytes (#106).
 *
 * `hash` and `measured` are both what the bytes turned out to be rather than
 * what the declaration said, which is why they arrive separately from it.
 *
 * `skipQueue` is {@link uploaderSkipsQueue}. When it is false the row is
 * pending on an insert and set back to pending on a replacement, so nothing on
 * this path puts an ordinary upload in front of the public. When it is true the
 * row is approved with `approval_source = 'bypass'`, and the audit trigger
 * records it as `bypassed`, replacement or not.
 *
 * Setting a replacement back to pending matters more than leaving it alone
 * would. An approved row that keeps its approval through a replacement is
 * serving bytes nobody has looked at, under a review a moderator gave to
 * different bytes, which is exactly what the queue exists to stop.
 * `approval_source` has to go with it: the table's `asset_approval_state_check`
 * will not have a pending row that still says what approved it, and it should
 * not, since nothing approved this.
 *
 * `tier` and `promoted_at` go back too. The new object is in the bucket, so a
 * row left saying `static` or `blob` would name a store the bytes are not in.
 * `bytes_missing_at` goes with them, because the bytes are no longer missing
 * (#336), and the table refuses a bucket row that still says they are.
 *
 * Replacement, not accumulation. One row per identity throughout, and the
 * superseded object stays in the store as an orphan for #113 rather than being
 * deleted here: deleting is the kind of thing that wants one owner and a list
 * of what nothing claims, not a best effort call on the end of an upload.
 *
 * Answers with the row's id rather than a boolean, and null when nothing was
 * written. #115 records where the upload came from in a second table keyed on
 * that id, and the id is a thing this function already has and the caller
 * otherwise would not.
 */
export async function writeUploadedAsset(
  supabase: SupabaseClient,
  userId: string,
  declaration: AssetUploadDeclaration,
  hash: string,
  path: string,
  measured: AssetImageDimensions,
  replacing: string | null,
  skipQueue: boolean,
): Promise<string | null> {
  const { identity } = declaration;
  const columns = assetColumns(declaration, hash, path, measured);
  const moderation = skipQueue
    ? { moderation: "approved", approval_source: "bypass" }
    : { moderation: "pending", approval_source: null };

  if (replacing) {
    const { error } = await supabase
      .from("asset")
      .update({
        ...columns,
        // Not defaulted the way an insert's is, so a replacement has to say
        // when the archive was last seen or the column would keep the first
        // upload's date and the hourly limit would not count this write.
        seen_at: new Date().toISOString(),
        promoted_at: null,
        bytes_missing_at: null,
        ...moderation,
      })
      .eq("id", replacing);

    return error ? null : replacing;
  }

  const { data, error } = await supabase
    .from("asset")
    .insert({
      game: identity.keyedOn === "unit" ? identity.game : null,
      unit_name: identity.keyedOn === "unit" ? identity.unitName : null,
      map_name: identity.keyedOn === "map" ? identity.mapName : null,
      variant: identity.variant,
      ...columns,
      ...moderation,
      uploaded_by: userId,
    })
    .select("id")
    .single();

  return error ? null : ((data as { id: string }).id ?? null);
}
