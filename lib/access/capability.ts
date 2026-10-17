/**
 * What an account may do beyond publishing its own items (issue #101).
 *
 * Types only. Nothing here reads or writes the database. The migration is
 * `supabase/migrations/20260814160000_user_capability.sql` and is the authority
 * on all of it: the list below is repeated there as a check constraint, which
 * no amount of TypeScript can keep in step, so `capability.test.ts` compares
 * the two the way `asset.test.ts` does.
 *
 * To ask whether the signed in visitor holds one, call the database:
 *
 * ```ts
 * const { data: mayBypass } = await supabase.rpc("has_capability", {
 *   capability: "can_publish_unreviewed",
 * })
 * ```
 *
 * That is the only way to ask. `public.user_capability` is readable by nobody,
 * and `has_capability()` answers for the caller alone.
 *
 * ## Second factors (#115, #145)
 *
 * An account that has a verified TOTP factor gets its capabilities only from a
 * session that has entered a code, which Supabase Auth marks `aal2` in the
 * token. An account with no factor keeps them on an aal1 session, so nobody
 * was locked out when this shipped. `capability_access()` is the same answer
 * with the reason attached, and `lib/access/secondFactor.ts` turns it into a
 * step up rather than a 404. The switch that requires a factor of every
 * capability holder is `capabilities_require_second_factor()`, see
 * `supabase/migrations/20261019100000_capability_second_factor.sql`.
 */

/**
 * A capability is granted on its own. Holding one says nothing about holding
 * another, which is the whole of the issue: `can_seed_unit_assets` bootstraps
 * content and `can_publish_unreviewed` waives a safety control, and a single
 * flag covering both hands out the second every time somebody is given the
 * first.
 *
 * `can_moderate` is in the same list because it is the same kind of thing, a
 * decision somebody made about one person rather than a property of the
 * account. `is_moderator()` reads it, so the moderation grid is unchanged.
 */
export const USER_CAPABILITIES = [
  /** Upload unit and map pictures that go live immediately. The maintainer
   * holds this. Granting it to anybody else is the point at which automated
   * screening has to be reconsidered. */
  "can_seed_unit_assets",
  /** Skip the moderation queue for whatever this person uploads. */
  "can_publish_unreviewed",
  /** See and withdraw anything, and read the report queue. A moderator's own
   * uploads also skip the queue, because they could approve them in the grid
   * anyway (`uploaderSkipsQueue` in `lib/assets/upload.ts`). */
  "can_moderate",
  /** Replace a picture another account uploaded, or one whose uploader has
   * closed their account, so a newer archive can refresh it (#138). The
   * replacement still goes to the queue and the row becomes this account's
   * (`uploaderMayReplaceAnyAsset` in `lib/assets/upload.ts`). */
  "can_replace_any_asset",
] as const;

export type UserCapability = (typeof USER_CAPABILITIES)[number];

/**
 * A row as the table stores it, in the table's own column names.
 *
 * `granted_by` is null where nobody with a session did the granting: the
 * maintainer's own capabilities, and the moderator rows that predate the
 * column.
 */
export interface UserCapabilityRow {
  user_id: string;
  capability: UserCapability;
  granted_at: string;
  granted_by: string | null;
}
