/**
 * Whether the hub may publish a given game's or map's pictures at all, and what
 * that answer rests on (issue #97).
 *
 * Types only, the same as `./asset`. The migration is
 * `supabase/migrations/20260814150000_asset_licence.sql` and is the authority.
 * `licence.test.ts` reads the literal list back out of it, because nothing in
 * TypeScript can keep a check constraint in step.
 *
 * Read this before writing an asset, not after. The durable tier is a public
 * git repository, so publishing something the licence did not allow is a
 * rewrite of a published history rather than a delete from a bucket.
 */

/**
 * Whether one class of picture may be published for one subject.
 *
 * `unknown` and `denied` both block. They are still separate, because one is a
 * gap in the research and the other is a settled no, and only the first is
 * worth looking at again.
 *
 * There is no fourth state and no absent state. A subject with no row at all
 * reads as `unknown`, so anything deciding whether to publish treats a missing
 * lookup and an undecided one identically. See {@link mayRedistribute}.
 */
export const ASSET_REDISTRIBUTION_STATES = ["unknown", "allowed", "denied"] as const;

export type AssetRedistribution = (typeof ASSET_REDISTRIBUTION_STATES)[number];

/**
 * A row as the table stores it, in the table's own column names.
 *
 * Exactly one of `game` and `map_name` is set. `game` is the modinfo shortname,
 * the value `asset.game` and `item.game_key` hold, and never a version.
 * `map_name` is the full canonical name including the version string, the value
 * `asset.map_name` holds, and is never split.
 *
 * The three evidence fields are nullable because "nobody could find out" is a
 * real finding worth recording. A null `licence` is not a permissive one.
 */
export interface AssetLicenceRow {
  id: string;

  game: string | null;
  map_name: string | null;

  /** An SPDX identifier where the project publishes a clean one, and a plain
   * description where the tree is mixed. Null when nobody could find out. */
  licence: string | null;
  /** Where the claim came from. The column that outlives the reasoning. */
  licence_url: string | null;
  /** Anything the other fields flatten, such as the one directory that differs. */
  notes: string | null;

  checked_at: string;
  /** A person, a handle, or the name of whatever automated the search. */
  checked_by: string;

  /** Images the archive already contains. */
  redistribute_extracted: AssetRedistribution;
  /** Images drawn from a model, which are derivative works and are not always
   * covered by the permission that covers extraction. */
  redistribute_rendered: AssetRedistribution;

  created_at: string;
  updated_at: string;
}

/**
 * Whether an asset of this origin may be published for this subject, given the
 * licence row found for it, or `undefined` when no row was found.
 *
 * Fails closed on every path that is not an explicit `allowed`: no row, an
 * undecided row, a refused row. That is the whole behaviour, and it is a
 * function rather than a comparison at each call site so that no caller can
 * accidentally write the one truthiness test that lets a missing row through.
 *
 * `uploaded` is not covered here. Nobody can tell from the bytes what an
 * uploaded image is a picture of or who made it, so a per game or per map
 * decision cannot answer for one. The moderation queue is what stands in front
 * of that class.
 */
export function mayRedistribute(
  licence: AssetLicenceRow | null | undefined,
  origin: "extracted" | "rendered",
): boolean {
  if (!licence) return false;
  const state =
    origin === "rendered" ? licence.redistribute_rendered : licence.redistribute_extracted;
  return state === "allowed";
}
