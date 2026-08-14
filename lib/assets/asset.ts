/**
 * How the rest of the hub talks about a row in `public.asset` (issue #100).
 *
 * Types only. Nothing here reads or writes the database: the routes that do
 * that arrive with #103, #104 and #106, and the schema is deliberately in place
 * before any of them.
 *
 * The migration is
 * `supabase/migrations/20260814090000_unit_and_map_assets.sql`, as amended by
 * `20260814120000_asset_origin_and_approval_source.sql`, and is the authority
 * on all of this. The literal lists below are repeated there as check
 * constraints, which no amount of TypeScript can keep in step, so
 * `asset.test.ts` compares the two the same way `container.test.ts` does for
 * `item.kind`.
 */

/**
 * Where the bytes actually live. `blob` is Vercel Blob, the staging tier
 * everything uploaded lands in. `static` is the durable tier served from the
 * assets repo, which the seed writes to directly and which promotion moves rows
 * to later.
 */
export const ASSET_TIERS = ["blob", "static"] as const;

export type AssetTier = (typeof ASSET_TIERS)[number];

/** Nothing is fetchable until the row says `approved`. */
export const ASSET_MODERATION_STATES = ["pending", "approved", "rejected"] as const;

export type AssetModeration = (typeof ASSET_MODERATION_STATES)[number];

/**
 * How the bytes were produced, not how they arrived. A client that extracts a
 * buildpic and then posts it writes `extracted`, because the archive can
 * produce those bytes again.
 *
 * `extracted` comes out of a game or map archive, `rendered` is drawn from the
 * unit's model, and `uploaded` is an image a person supplied themselves. The
 * last one is the class the moderation queue exists for: the other two can be
 * re-derived and checked against a source archive, and an uploaded one is
 * whatever bytes somebody chose.
 */
export const ASSET_ORIGINS = ["extracted", "rendered", "uploaded"] as const;

export type AssetOrigin = (typeof ASSET_ORIGINS)[number];

/**
 * Which authority put the row in front of the public, once something did.
 *
 * `seed` is the hand curated corpus written straight to the durable tier,
 * `bypass` is an uploader holding a capability that skips the queue, and
 * `moderator` is a person approving it in the grid. The first two are both
 * bypasses and are still separate, because seeding content and waiving a
 * safety control are separate grants and the audit trail has to say which one
 * was used.
 *
 * Null while pending, and on a rejected row it reads "how this was approved
 * before it was rejected", or null if it never was.
 */
export const ASSET_APPROVAL_SOURCES = ["seed", "bypass", "moderator"] as const;

export type AssetApprovalSource = (typeof ASSET_APPROVAL_SOURCES)[number];

/** The only variant a unit has besides a render. */
export const UNIT_BUILDPIC_VARIANT = "buildpic";

/** A unit's other variants are `render:<angle>`. The angle is part of the key,
 * so two renders of one unit from different angles are two assets. */
export const UNIT_RENDER_VARIANT_PREFIX = "render:";

/**
 * The map side of the variant vocabulary, which #105 is the first change to
 * know in full: the minimap texture, and the three extracted overlay layers.
 *
 * A closed list, unlike the unit side, because nothing here is open ended the
 * way a render angle is. The table left it unconstrained while the issues that
 * name these were unwritten, and the caps in `./caps` name all four, so the
 * check constraint arrives with them.
 */
/**
 * The one variant that carries a world height range, because it is the one
 * whose samples mean nothing without it.
 */
export const MAP_HEIGHT_OVERLAY_VARIANT = "overlay:height";

export const MAP_VARIANTS = [
  "minimap",
  "overlay:metal",
  "overlay:type",
  MAP_HEIGHT_OVERLAY_VARIANT,
] as const;

export type MapVariant = (typeof MAP_VARIANTS)[number];

export function isMapVariant(value: string): value is MapVariant {
  return (MAP_VARIANTS as readonly string[]).includes(value);
}

/**
 * Which of the two keys addresses this asset. They are different shapes on
 * purpose and are not unified, so a caller has to say which one it means rather
 * than filling in whichever fields it happens to have.
 *
 * `game` is the game's shortname, the same value `item.game_key` holds, and
 * never a version: one set of pictures per game, replaced by a newer archive
 * rather than added to.
 *
 * `mapName` is the full canonical name the engine reports, version string and
 * all. Do not split a version out of it. A map is not scoped to a game, because
 * the same map archive is used across all of them.
 */
export type AssetIdentity =
  | { keyedOn: "unit"; game: string; unitName: string; variant: string }
  | { keyedOn: "map"; mapName: string; variant: string };

/**
 * A row as the table stores it, in the table's own column names, so a
 * PostgREST result can be typed without a translation step in between.
 *
 * The nullability here is the table's, not a convenience: `game` and `unit_name`
 * are null on a map row, `map_name` is null on a unit row, and `map_width` and
 * `map_height` are set on a map row and null on a unit row. Read
 * {@link AssetIdentity} rather than testing the columns by hand.
 */
export interface AssetRow {
  id: string;

  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;

  /** Over the raw archive bytes. This is what carries identity: dedupe, the
   * batch have check and the anomaly check all compare on it. */
  source_hash: string;
  /** Over the encoded bytes, and the path component. Legitimately differs
   * between Coilbox builds, so never compare on it to decide whether the hub
   * already has something. */
  hash: string;

  encode_profile: string;
  /** Tier relative, never a fully qualified URL. */
  path: string;
  origin: AssetOrigin;
  tier: AssetTier;

  mime: string;
  bytes: number;
  /** The encoded image, in pixels. */
  width: number;
  height: number;
  /** The map, in world units. Not the minimap texture. Null on a unit row. */
  map_width: number | null;
  map_height: number | null;

  /** Provenance only. Nothing keys, joins or filters on these two. */
  source_archive: string;
  seen_at: string;

  promoted_at: string | null;
  uploaded_by: string | null;
  moderation: AssetModeration;
  /** Null while pending, and null on a row rejected without ever having been
   * approved. Set for anything the hub is serving. */
  approval_source: AssetApprovalSource | null;

  created_at: string;
  updated_at: string;
}
