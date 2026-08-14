/**
 * How the rest of the hub talks about a row in `public.asset` (issue #100).
 *
 * Types only. Nothing here reads or writes the database: the routes that do
 * that arrive with #103, #104 and #106, and the schema is deliberately in place
 * before any of them.
 *
 * The migration is
 * `supabase/migrations/20260814090000_unit_and_map_assets.sql` and is the
 * authority on all of this. The two literal lists below are repeated there as
 * check constraints, which no amount of TypeScript can keep in step, so
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

/** The only variant a unit has besides a render. */
export const UNIT_BUILDPIC_VARIANT = "buildpic";

/** A unit's other variants are `render:<angle>`. The angle is part of the key,
 * so two renders of one unit from different angles are two assets. */
export const UNIT_RENDER_VARIANT_PREFIX = "render:";

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
  origin: string;
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
  approval_source: string | null;

  created_at: string;
  updated_at: string;
}
