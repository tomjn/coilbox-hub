import type { SupabaseClient } from "@supabase/supabase-js";
import {
  type AssetIdentity,
  type AssetModeration,
  type AssetTier,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "./asset";
import { blobTierUrl } from "./blob";
import { staticTierUrl } from "./cdn";
import { identityFilter, identityKey, queryChunks, rowIdentity } from "./have";
import { type Footprint, type MissingPicture, missingPicture } from "./placeholder";

/**
 * The one place that turns an asset into something a page can render (issue
 * #108).
 *
 * A caller asks by identity and never by tier. Which tier serves a picture is a
 * storage detail that comes off the row, so a component that wanted a minimap
 * has no reason to know that this one is still in Blob and that one has been
 * promoted.
 *
 * The last rung always succeeds. Nothing here can answer "no", so a caller never
 * has to invent a fallback of its own and the site never shows a broken image.
 *
 * ## The ladder
 *
 * 1. The atlas for the game, buildpics only, already in the bundle. Not built.
 *    See the note below.
 * 2. The durable tier on GitHub Pages, which is off Vercel's meters entirely.
 * 3. Blob, for anything not promoted yet.
 * 4. The buildpic, when a render angle is missing.
 * 5. A placeholder drawn from the footprint and the name.
 *
 * Two and three are one lookup rather than two attempts. There is exactly one
 * row per identity, guaranteed by `asset_unit_identity_idx` and
 * `asset_map_identity_idx`, and that row's `tier` column says which of the two
 * stores holds it. So the order between them is a fact about promotion, not
 * something this file races: a promoted row reads `static` and an unpromoted one
 * reads `blob`, and neither state is ever ambiguous.
 *
 * ## Nothing here can serve a pending or a rejected row
 *
 * Three separate things have to fail before one could.
 *
 * `asset_read_approved` in `20260814180000_asset_access.sql` shows `anon` and
 * `authenticated` approved rows and nothing else, so a page reading with a
 * session or the publishable key cannot see one. {@link fetchHeldAssets} filters
 * on `moderation` anyway, so passing it the admin client does not widen it. And
 * {@link resolveAsset} drops any row that is not approved before it looks at the
 * tier, so a row that reached the lookup some other way still never becomes a
 * URL.
 *
 * A pending row is therefore indistinguishable from no row at all: the identity
 * falls through to the buildpic substitute and then to the placeholder. That is
 * the point. A pending upload's Blob path is a working public URL, and the only
 * thing keeping unreviewed bytes out of sight is that nobody outside the hub
 * knows it (#131), so this resolver must never be the second way to reach one.
 * `app/moderation/assets/[id]/route.ts` is the only path to unapproved bytes and
 * it checks `is_moderator()` per request.
 *
 * ## The atlas rung is a name and not a stub
 *
 * #112 builds one packed WebP per game plus a JSON map of `unit_name` to
 * `{x, y, w, h}`. It does not exist, there is no atlas to consult, and a branch
 * guarded by a value that is always absent is dead code nobody can test.
 *
 * So the rung is omitted, and what is left for it is the shape of the answer.
 * Serving from an atlas is a URL plus a crop rectangle, which is not the same
 * answer as serving a whole object, and a caller that reads `.url` off whatever
 * comes back would draw the entire sheet in place of one unit. {@link
 * ResolvedAsset} is therefore a union a caller has to switch over, and
 * {@link ASSET_SOURCES} names the members. #112 adds `"atlas"` to that list, adds
 * a member carrying the crop, and every exhaustive switch stops compiling until
 * it is handled, which is the failure worth having.
 */

/**
 * Which rung answered.
 *
 * The two tiers keep their own names rather than collapsing into one "stored",
 * because which one served a picture is the difference between a request that
 * costs nothing and one that spends Blob data transfer, and that is worth being
 * able to see.
 *
 * #112 adds `"atlas"` here.
 */
export const ASSET_SOURCES = ["static", "blob", "placeholder"] as const;

export type AssetSource = (typeof ASSET_SOURCES)[number];

/** A picture the hub holds, at an absolute URL. */
export interface ServedAsset {
  from: AssetTier;
  url: string;
  /**
   * Whose bytes these actually are, which is not always what was asked for.
   *
   * A missing `render:270` is served the unit's buildpic, so a caller that
   * assumed it got the angle it asked for would label a head-on icon as a view
   * from behind. Read this rather than the identity that was passed in.
   */
  served: AssetIdentity;
  /** Whether {@link served} differs from the identity asked for. Derivable, and
   *  here anyway because alt text is wrong without it and a caller that has to
   *  remember to compare will not. */
  substituted: boolean;
  /** The encoded image in pixels, off the row, so an `<img>` can carry its own
   *  dimensions and not shift the page when it loads. */
  width: number;
  height: number;
}

/** Nothing stored, so the hub draws it. See `./placeholder`. */
export type PlaceholderAsset = { from: "placeholder" } & MissingPicture;

export type ResolvedAsset = ServedAsset | PlaceholderAsset;

/**
 * The columns serving needs. Narrow on purpose: this is the public path, and
 * every column named here is one a page may end up disclosing.
 */
const SERVE_COLUMNS =
  "game, unit_name, map_name, variant, tier, path, width, height, moderation";

/** A row as far as serving is concerned. */
export interface HeldRow {
  tier: AssetTier;
  /** Tier relative, never a fully qualified URL. */
  path: string;
  width: number;
  height: number;
  moderation: AssetModeration;
}

/** What the hub holds for a set of identities, keyed by {@link identityKey}. An
 *  identity with no row is simply absent. */
export type HeldAssets = ReadonlyMap<string, HeldRow>;

/**
 * The absolute URL for a tier relative `asset.path`, given the tier off the row.
 *
 * The junction between the two tier modules and the only place that chooses
 * between them. `app/moderation/assets/[id]/route.ts` calls it directly through
 * `./queue`, because a moderator has to see the picture itself and every rung
 * this file adds above and below is something to show in place of one.
 */
export function assetTierUrl(tier: AssetTier, path: string): string {
  return tier === "static" ? staticTierUrl(path) : blobTierUrl(path);
}

/** The buildpic that stands in for a missing render, or null when the identity
 *  is not a unit render and nothing stands in for it. A map's overlays and a
 *  minimap have no substitute: they are different pictures of different things,
 *  not different views of one. */
export function buildpicSubstitute(identity: AssetIdentity): AssetIdentity | null {
  if (identity.keyedOn !== "unit") return null;
  if (!identity.variant.startsWith(UNIT_RENDER_VARIANT_PREFIX)) return null;

  return { ...identity, variant: UNIT_BUILDPIC_VARIANT };
}

/**
 * Every identity the ladder may need in order to answer for these, deduplicated
 * and in the order they were asked for.
 *
 * The buildpic behind each render is added here rather than by the caller, so a
 * page asking for renders does not have to know that a substitute exists. The
 * substitution rung stays in this file, which is the whole point of the file.
 */
export function ladderIdentities(identities: AssetIdentity[]): AssetIdentity[] {
  const wanted = new Map<string, AssetIdentity>();

  for (const identity of identities) {
    for (const needed of [identity, buildpicSubstitute(identity)]) {
      if (needed) wanted.set(identityKey(needed), needed);
    }
  }

  return [...wanted.values()];
}

/**
 * What the hub holds for these identities, ready for {@link resolveAsset}.
 *
 * Wants a session or anonymous client. The admin client would work and is not
 * wrong, since the filter and the resolver both hold the line on their own, but
 * row level security is the layer worth keeping underneath both of them.
 *
 * A chunk that errors is dropped rather than thrown. A picture lookup that fails
 * means the hub does not know what it holds, and the honest render for that is
 * the placeholder: it says there is no picture, which is what the page can
 * actually show. Turning it into a 500 would take down an item page over a
 * thumbnail.
 */
export async function fetchHeldAssets(
  supabase: SupabaseClient,
  identities: AssetIdentity[],
): Promise<HeldAssets> {
  const wanted = ladderIdentities(identities);
  const held = new Map<string, HeldRow>();
  if (wanted.length === 0) return held;

  const responses = await Promise.all(
    queryChunks(wanted).map((chunk) =>
      supabase
        .from("asset")
        .select(SERVE_COLUMNS)
        .eq("moderation", "approved")
        .or(chunk.map(identityFilter).join(",")),
    ),
  );

  for (const { data, error } of responses) {
    if (error || !data) continue;

    for (const row of data as unknown as (HeldRow & Parameters<typeof rowIdentity>[0])[]) {
      held.set(identityKey(rowIdentity(row)), {
        tier: row.tier,
        path: row.path,
        width: row.width,
        height: row.height,
        moderation: row.moderation,
      });
    }
  }

  return held;
}

/** The row for an identity, only if it is one the public may be shown. */
function servable(held: HeldAssets, identity: AssetIdentity): HeldRow | null {
  const row = held.get(identityKey(identity));
  return row && row.moderation === "approved" ? row : null;
}

function serve(asked: AssetIdentity, served: AssetIdentity, row: HeldRow): ServedAsset {
  return {
    from: row.tier,
    url: assetTierUrl(row.tier, row.path),
    served,
    substituted: identityKey(asked) !== identityKey(served),
    width: row.width,
    height: row.height,
  };
}

/**
 * The picture to show for one identity. Never fails.
 *
 * `footprint` is what the placeholder is drawn from when there is nothing
 * stored, and the caller is the only thing that has it: a unit's footprint comes
 * from the blueprint payload and a map's size from BAR's map list, and neither
 * is in `public.asset` for a row that does not exist. Read `./placeholder` for
 * the units each one is in.
 */
export function resolveAsset(
  identity: AssetIdentity,
  held: HeldAssets,
  footprint: Footprint | null = null,
): ResolvedAsset {
  // 1. The atlas. #112, and see the note at the top of this file.

  // 2 and 3. Whichever tier the row says.
  const own = servable(held, identity);
  if (own) return serve(identity, identity, own);

  // 4. The buildpic, when a render angle is missing.
  const instead = buildpicSubstitute(identity);
  if (instead) {
    const row = servable(held, instead);
    if (row) return serve(identity, instead, row);
  }

  // 5. The rung that cannot fail.
  return { from: "placeholder", ...missingPicture(identity, footprint) };
}
