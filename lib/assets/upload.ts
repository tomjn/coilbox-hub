import type { SupabaseClient } from "@supabase/supabase-js";
import { type AssetIdentity, type AssetOrigin, UNIT_RENDER_VARIANT_PREFIX } from "./asset";
import { BLOB_ADVANCED_OPERATIONS_PER_MONTH } from "./blob";
import { capForVariant, heightOverlayMaxBytes } from "./caps";
import { identityFilter } from "./have";
import { ASSET_MIME_EXTENSIONS, assetObjectPath, isAssetMime } from "./path";
import { type SourceConflict, sourceConflict } from "./sourceConflict";

/**
 * Everything an upload is refused for, in one place (issue #104).
 *
 * ## Why this is a module and not a route
 *
 * It was written for two upload paths, so that a check could not exist on one
 * and not the other. There is one now: everything posts the bytes to
 * `POST /api/v1/assets/upload` and the route calls `put()`. The client direct
 * path went in #133, because the browser SDK hands the uploader the finished
 * URL of its own unreviewed picture.
 *
 * It stays a module anyway. The route reads as a sequence of refusals with the
 * write at the end, and {@link checkAssetUpload} is where the reason for each
 * refusal and its cost live. #107 owns its numbers.
 *
 * ## Why every check is before the write
 *
 * `put()` is an advanced operation. A Hobby Blob store gets 2,000 a month,
 * exceeding that removes Blob access for 30 days, and there is no overage
 * billing, so it cannot be paid through. A rejected upload therefore has to
 * cost nothing at all, which means nothing here may run after the write and the
 * checks are ordered cheapest first: everything answerable from the request
 * alone before anything that asks the database.
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
 * `overlay:height` used to, and #142 is why it does not: it is 16 bit at the
 * map's own resolution, so a large map's runs to four megabytes and this number
 * was refusing seven of the ninety seven maps in the collection. It gets a cap
 * off the declared map size instead, in `heightOverlayMaxBytes`. The other two
 * are 8 bit and heavily quantised, nothing measured says they come near this,
 * and nothing measured says where their grid is either.
 */
export const ASSET_MAX_OBJECT_BYTES = 2 * 1024 * 1024;

/**
 * How much of the store one account may hold.
 *
 * The whole store is 1 GB, so this is a sixteenth of it. It is a ceiling on one
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
 * references, not the roster. A hundred an hour is far past that and is
 * insurance against a client looping rather than a pace anybody meets, in the
 * spirit of `enforce_publish_rate_limit` on `public.item`.
 *
 * Counted on `seen_at`, for the reason {@link MONTHLY_UPLOAD_BUDGET} gives: a
 * replacement is an upload as far as anything that costs money is concerned.
 *
 * One rule with a subject rather than two rules, because a map asset is not
 * scoped to a game and inventing a game for it would either exempt maps or
 * force a second limit that drifts from this one.
 */
export const SUBJECT_UPLOADS_PER_HOUR = 100;

/**
 * How many uploads the hub will accept in a calendar month.
 *
 * Every accepted upload is one `put()` and therefore one advanced operation,
 * and going over the store's allowance is a 30 day outage that cannot be paid
 * through. The margin below the allowance is deliberate: this counts writes the
 * hub made, so anything that spends an operation outside this route, or a write
 * rolled back afterwards, eats into the margin rather than into the outage.
 *
 * Counted on `seen_at` rather than `created_at`, because a replacement (#106)
 * spends an operation without creating a row. `created_at` would read a client
 * looping on replacements as no uploads at all, which is the one way to reach
 * the lockout unnoticed. `updated_at` is wrong in the other direction, since
 * approving a row in the moderation grid touches it and spends nothing.
 * `seen_at` is written by exactly the two things that call `put()`: a first
 * upload and a replacement.
 */
export const MONTHLY_UPLOAD_BUDGET = BLOB_ADVANCED_OPERATIONS_PER_MONTH - 100;

/**
 * What a client says it is uploading. Every field here ends up on the row
 * except the ones the hub decides for itself: `path`, `tier`, `moderation`,
 * `approval_source` and `uploaded_by`.
 *
 * `width` and `height` are not here, and their absence is #105's answer. The
 * hub measures the image header, so a declared pair could only agree with the
 * bytes or be wrong, and there is no third thing a client could usefully mean
 * by it. {@link writePendingAsset} takes the measured pair separately.
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
      /**
       * The staging object that already holds these exact bytes, when the store
       * has one (#132). Present means the caller writes nothing and puts this
       * pathname on the row, which is an advanced operation saved.
       *
       * Absent on almost every upload, so it is optional in the same way
       * `conflict` is rather than a null the caller has to read past.
       */
      stored?: string;
      conflict?: SourceConflict;
    }
  | { ok: false; error: string; status: number; conflict?: SourceConflict };

/** What the identity check needs off a row that already exists. Each column is
 * a question a later check asks: who may replace it, whether these are the same
 * source bytes, which archive those bytes came out of, whether it is in a state
 * that may be replaced at all, and how much of the account's quota the
 * superseded row is holding. */
const EXISTING_COLUMNS = "id, source_hash, source_archive, uploaded_by, moderation, bytes";

interface ExistingAsset {
  id: string;
  source_hash: string;
  source_archive: string;
  uploaded_by: string | null;
  moderation: string;
  bytes: number;
}

/** Beginning of the current calendar month, in UTC, as PostgREST wants it. */
function monthStart(now: Date): string {
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1)).toISOString();
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
 * The staging object already holding these bytes, or null when there is none.
 *
 * The whole of #132's saving. Paths are content addressed on the hash of the
 * encoded bytes and the hub computes that hash (#154), so a match here is an
 * object byte for byte identical to the one this upload is about to write, and
 * writing it again would spend one advanced operation out of 2,000 a month to
 * leave the store exactly as it was. Placeholder buildpics repeat across a
 * roster, so this is not a rare shape.
 *
 * A function rather than a filter, because "already holding" excludes objects
 * that are on their way out and no PostgREST query says that in one round trip.
 * `20260814260000_asset_object_reuse.sql` is where the rule is.
 *
 * Null when the query fails, which is the right way for an optimisation to fail:
 * the upload writes its own object and costs what it always cost. Nothing else
 * in this module reads a failed query as an absence, and the difference is that
 * every other one is a limit.
 */
async function reusableObject(supabase: SupabaseClient, hash: string): Promise<string | null> {
  const { data, error } = await supabase.rpc("reusable_staging_object", { object_hash: hash });

  return !error && typeof data === "string" ? data : null;
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

  // Three sources, in order of how much each knows about the picture. The
  // class's own number where the class fixes a longest edge. The one the
  // declared map size implies where it does not and the class is sampled from a
  // grid whose resolution that size gives (#142). The global backstop where
  // neither answers. A null from all three is a variant the hub stores nothing
  // for, which `checkAssetImage` has already refused and which the path check
  // below refuses again, so it takes the backstop rather than an exemption.
  const maxBytes =
    capForVariant(identity.variant)?.maxBytes ??
    heightOverlayMaxBytes(identity.variant, declaration.mapWidth, declaration.mapHeight) ??
    ASSET_MAX_OBJECT_BYTES;
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

  const [existing, stored, unitRenders, accountBytes, recent, thisMonth] =
    await Promise.all([
      fetchExisting(supabase, identity),
      reusableObject(supabase, hash),
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
      countRows(
        supabase
          .from("asset")
          .select("id", { count: "exact", head: true })
          .not("uploaded_by", "is", null)
          .gte("seen_at", monthStart(new Date())),
      ),
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
    if (replacing.source_hash === declaration.sourceHash) {
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

  if (thisMonth === null) return QUOTA_UNAVAILABLE;
  if (thisMonth >= MONTHLY_UPLOAD_BUDGET) {
    return {
      ok: false,
      error: "The hub has reached its upload allowance for this month. Try again next month.",
      status: 503,
    };
  }

  return {
    ok: true,
    path,
    replacing: replacing?.id ?? null,
    ...(stored ? { stored } : {}),
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
    tier: "blob",
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
 * Write the pending row for an upload the hub has just accepted, replacing the
 * row named by `replacing` when a newer archive changed the bytes (#106).
 *
 * `hash` and `measured` are both what the bytes turned out to be rather than
 * what the declaration said, which is why they arrive separately from it.
 *
 * `moderation` and `approval_source` are left at their defaults on an insert
 * and set back to them on a replacement, so nothing on this path can put a row
 * in front of the public. Bypass on a capability is #114's alongside the queue
 * that would otherwise hold it.
 *
 * Setting them back matters more than leaving them alone would. An approved row
 * that keeps its approval through a replacement is serving bytes nobody has
 * looked at, under a review a moderator gave to different bytes, which is
 * exactly what the queue exists to stop. `approval_source` has to go with it:
 * the table's `asset_approval_state_check` will not have a pending row that
 * still says what approved it, and it should not, since nothing approved this.
 *
 * `tier` and `promoted_at` go back too. The new object is in Blob, so a row
 * left saying `static` would name a durable tier path the bytes are not at.
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
export async function writePendingAsset(
  supabase: SupabaseClient,
  userId: string,
  declaration: AssetUploadDeclaration,
  hash: string,
  path: string,
  measured: AssetImageDimensions,
  replacing: string | null,
): Promise<string | null> {
  const { identity } = declaration;
  const columns = assetColumns(declaration, hash, path, measured);

  if (replacing) {
    const { error } = await supabase
      .from("asset")
      .update({
        ...columns,
        // Not defaulted the way an insert's is, so a replacement has to say
        // when the archive was last seen or the column would keep the first
        // upload's date and the monthly budget would not count this write.
        seen_at: new Date().toISOString(),
        promoted_at: null,
        moderation: "pending",
        approval_source: null,
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
      uploaded_by: userId,
    })
    .select("id")
    .single();

  return error ? null : ((data as { id: string }).id ?? null);
}
