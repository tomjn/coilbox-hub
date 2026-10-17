import type { SupabaseClient } from "@supabase/supabase-js";
import type {
  AssetApprovalSource,
  AssetEventAction,
  AssetModeration,
  AssetRejectionKind,
} from "./asset";
import { isUuid, pictureCaption } from "./queue";

/**
 * Reading `public.asset_event` back (issue #115).
 *
 * The issue asks for a way to enumerate everything one account seeded, so that
 * a trusted account gone bad can be unwound rather than argued about. Unwinding
 * needs two lists and they are not the same list.
 *
 * What the account uploaded comes off `public.asset.uploaded_by` and includes
 * things nobody has looked at yet. What the account did comes off
 * `public.asset_event.actor` and includes decisions it made about other
 * people's pictures, which is what a moderator account gone bad looks like.
 * Neither one contains the other, so both are here.
 *
 * Everything reads as `service_role`, the same as the contact sheet and for the
 * same reason: `asset_event` grants nothing to `anon` or `authenticated`, so
 * there is no session that reads it, and the page checks `is_moderator()`
 * before it asks.
 */

/** Enough of a page that a day of moderation fits in one look, and bounded so
 * that a table which only ever grows cannot turn this into a slow page. */
export const TRAIL_PAGE_SIZE = 200;

/** The row as the table stores it, with the asset embedded for its caption.
 * PostgREST returns an embedded to-one relationship as an object. */
interface EventRow {
  id: number;
  action: AssetEventAction;
  rejection_kind: AssetRejectionKind | null;
  actor: string | null;
  uploader: string | null;
  uploader_display_name: string | null;
  uploader_provider_name: string | null;
  at: string;
  asset: {
    id: string;
    game: string | null;
    unit_name: string | null;
    map_name: string | null;
    faction_key: string | null;
    variant: string;
  } | null;
}

const EVENT_COLUMNS =
  "id, action, rejection_kind, actor, uploader, uploader_display_name, uploader_provider_name, at, asset(id, game, unit_name, map_name, faction_key, variant)";

export interface TrailEvent {
  id: number;
  action: AssetEventAction;
  /** Set on a rejection and null on everything else, which is the distinction
   * the whole table exists to keep. */
  rejectionKind: AssetRejectionKind | null;
  /** Who decided, or null where nothing was signed in, which today means a
   * write the upload route made with the secret key. */
  actor: string | null;
  uploader: string | null;
  /** Who uploaded the picture by name, kept on a safety rejection and on
   * nothing else (#146), so the record still says who it was once the account
   * has closed. Null on every other event. */
  uploaderName: UploaderName | null;
  at: string;
  assetId: string;
  /** What the picture is of, for a moderator reading down the list. */
  name: string;
  detail: string;
}

export interface UploaderName {
  /** The hub display name the account had when the picture was rejected. */
  displayName: string | null;
  /** The username the account signed in with, Discord's for every account
   * today. */
  providerName: string | null;
  /** Whether the account has been deleted since, which is when these names are
   * all that is left of it. */
  accountClosed: boolean;
}

function toEvent(row: EventRow, open: Set<string>): TrailEvent {
  const caption = row.asset
    ? pictureCaption(row.asset)
    : { name: "A picture that is no longer there", detail: "" };

  return {
    id: row.id,
    action: row.action,
    rejectionKind: row.rejection_kind,
    actor: row.actor,
    uploader: row.uploader,
    uploaderName:
      row.uploader_display_name === null && row.uploader_provider_name === null
        ? null
        : {
            displayName: row.uploader_display_name,
            providerName: row.uploader_provider_name,
            accountClosed: !row.uploader || !open.has(row.uploader),
          },
    at: row.at,
    assetId: row.asset?.id ?? "",
    ...caption,
  };
}

/**
 * The events, with each kept name marked by whether its account still exists.
 *
 * Every account has a `public.profile` row from the moment it is made, and the
 * row goes with the account, so a missing profile is a closed account. Only the
 * uploaders of events that kept a name are asked about.
 */
async function toEvents(supabase: SupabaseClient, rows: EventRow[]): Promise<TrailEvent[]> {
  const named = [
    ...new Set(
      rows
        .filter((row) => row.uploader && (row.uploader_display_name || row.uploader_provider_name))
        .map((row) => row.uploader as string),
    ),
  ];

  const open = new Set<string>();
  if (named.length > 0) {
    const { data, error } = await supabase.from("profile").select("user_id").in("user_id", named);
    if (error) throw new Error(`Could not check which uploaders still have accounts: ${error.message}`);
    for (const profile of (data ?? []) as { user_id: string }[]) open.add(profile.user_id);
  }

  return rows.map((row) => toEvent(row, open));
}

/** Everything that has happened lately, newest first, which is the view a
 * moderator opens without a particular account in mind. */
export async function fetchRecentEvents(
  supabase: SupabaseClient,
  limit = TRAIL_PAGE_SIZE,
): Promise<TrailEvent[]> {
  const { data } = await supabase
    .from("asset_event")
    .select(EVENT_COLUMNS)
    .order("at", { ascending: false })
    .limit(limit);

  return toEvents(supabase, (data ?? []) as unknown as EventRow[]);
}

/**
 * Everything one account is behind, as either the decider or the uploader.
 *
 * Both, rather than the actor alone, because an account that uploaded a picture
 * somebody else approved is still the account the picture came from, and a
 * report about that picture is about them.
 */
export async function fetchAccountEvents(
  supabase: SupabaseClient,
  account: string,
  limit = TRAIL_PAGE_SIZE,
): Promise<TrailEvent[]> {
  // The account comes off a query string and goes into a PostgREST `or`, which
  // is a filter grammar of its own rather than a bound parameter. Held to the
  // shape of a uuid here as well as in the page, because this is the call that
  // builds the string.
  if (!isUuid(account)) return [];

  const { data } = await supabase
    .from("asset_event")
    .select(EVENT_COLUMNS)
    .or(`actor.eq.${account},uploader.eq.${account}`)
    .order("at", { ascending: false })
    .limit(limit);

  return toEvents(supabase, (data ?? []) as unknown as EventRow[]);
}

interface AccountAssetRow {
  id: string;
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  faction_key: string | null;
  variant: string;
  moderation: AssetModeration;
  rejection_kind: AssetRejectionKind | null;
  approval_source: AssetApprovalSource | null;
  origin: string;
  created_at: string;
}

const ACCOUNT_ASSET_COLUMNS =
  "id, game, unit_name, map_name, faction_key, variant, moderation, rejection_kind, approval_source, origin, created_at";

export interface TrailAsset {
  id: string;
  name: string;
  detail: string;
  moderation: AssetModeration;
  rejectionKind: AssetRejectionKind | null;
  approvalSource: AssetApprovalSource | null;
  origin: string;
  createdAt: string;
}

export interface AccountTrail {
  /** Every picture the account uploaded, whatever state it is in. */
  uploaded: TrailAsset[];
  /** How many there are in total, which is more than {@link uploaded} once an
   * account has more than a page of them. An account being unwound is exactly
   * the one likely to. */
  uploadedTotal: number;
  events: TrailEvent[];
}

/**
 * One account's whole record.
 *
 * Two queries rather than a join, because the two lists answer different
 * questions and only one of them can be counted usefully. Nothing dedupes them
 * against each other on purpose: a picture appearing in both is a picture the
 * account uploaded and then had a decision made about, and hiding the second
 * mention would hide the decision.
 */
export async function fetchAccountTrail(
  supabase: SupabaseClient,
  account: string,
  limit = TRAIL_PAGE_SIZE,
): Promise<AccountTrail> {
  const [assets, events] = await Promise.all([
    supabase
      .from("asset")
      .select(ACCOUNT_ASSET_COLUMNS, { count: "exact" })
      .eq("uploaded_by", account)
      .order("created_at", { ascending: false })
      .limit(limit),
    fetchAccountEvents(supabase, account, limit),
  ]);

  const rows = (assets.data ?? []) as unknown as AccountAssetRow[];

  return {
    uploaded: rows.map((row) => ({
      id: row.id,
      ...pictureCaption(row),
      moderation: row.moderation,
      rejectionKind: row.rejection_kind,
      approvalSource: row.approval_source,
      origin: row.origin,
      createdAt: row.created_at,
    })),
    uploadedTotal: assets.count ?? rows.length,
    events,
  };
}

/**
 * How an event reads in a sentence.
 *
 * A rejection says which kind in the line itself rather than in a column
 * beside it, because the two are different enough that a reader skimming must
 * not have to look sideways to tell them apart.
 */
export function eventLine(event: TrailEvent): string {
  switch (event.action) {
    case "seeded":
      return "Seeded straight into the corpus";
    case "bypassed":
      return "Published without review, on a capability";
    case "approved":
      return "Approved by a moderator";
    case "returned":
      return "Returned to the queue";
    case "conflict_acknowledged":
      return "Source disagreement looked at and cleared, picture left as it was";
    case "replaced":
      return "Replaced another account's picture, on a capability";
    case "rejected":
      return event.rejectionKind === "safety"
        ? "Rejected on safety grounds, which is final"
        : `Rejected as ${event.rejectionKind}`;
  }
}

/**
 * Who uploaded the picture, by the names a safety rejection kept (#146), or
 * null where none were kept.
 *
 * Once the account has closed these names are all that identifies it, and the
 * line says so, so a moderator knows the uuid beside it leads nowhere.
 */
export function uploaderNameLine(event: TrailEvent): string | null {
  const kept = event.uploaderName;
  if (!kept || (!kept.displayName && !kept.providerName)) return null;

  const names = [
    kept.displayName ? `Uploaded by ${kept.displayName}` : "Uploaded",
    kept.providerName ? `signed in as ${kept.providerName}` : null,
  ]
    .filter(Boolean)
    .join(", ");

  return kept.accountClosed ? `${names}. Name kept after the account closed.` : names;
}
