import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationCrumb, ModerationNav } from "@/components/ModerationNav";
import { isUuid } from "@/lib/assets/queue";
import {
  type AccountTrail,
  eventLine,
  fetchAccountTrail,
  fetchRecentEvents,
  type TrailAsset,
  type TrailEvent,
  TRAIL_PAGE_SIZE,
} from "@/lib/assets/trail";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import { returnToQueue } from "./actions";

/**
 * What has been done to the pictures, and by whom (issue #115).
 *
 * The issue asks for a way to enumerate everything one account seeded, so that
 * a trusted account gone bad can be unwound rather than argued about. This is
 * that, and it is one page rather than a section of an admin area because there
 * are two things to show and they lead into each other: what happened lately,
 * and then everything behind one account once a line in that list gives you a
 * reason to ask.
 *
 * Every account here is a link that filters the page to it, so following a bad
 * decision to the whole of what that account has done is a click rather than a
 * query somebody has to write while something is going wrong.
 *
 * ## What the browser is given
 *
 * Row ids, account ids and what the pictures are of. Not the paths, for the
 * reason `lib/assets/queue.ts` sets out at length: on a pending or rejected row
 * the path is a working URL into a public store. Thumbnails are not on this
 * page at all, which is the other half of that: a moderator can open any row
 * through `/moderation/assets/[id]`, and a rejected picture is not something to
 * put in front of somebody who came here to read a list.
 */

const BACKDROP_STRENGTH = 0.08;

/** An account id in full is 36 characters and there are two of them on most
 * lines. The first stretch is enough to tell two apart while reading, and the
 * whole thing is on the link for anybody who needs to copy it. */
function shortId(id: string): string {
  return id.slice(0, 8);
}

function when(at: string): string {
  return at.replace("T", " ").slice(0, 16);
}

function AccountLink({ id, label }: { id: string; label: string }) {
  return (
    <Link
      href={`/moderation/trail?account=${id}`}
      title={`${label} ${id}`}
      className="text-neutral-400 underline decoration-neutral-700 underline-offset-2 transition-colors hover:text-neutral-200"
    >
      {label} {shortId(id)}
    </Link>
  );
}

/** The bytes, for a moderator who has to say what the picture actually was. */
function PictureLink({ event }: { event: Pick<TrailEvent, "assetId" | "name" | "detail"> }) {
  return (
    <Link
      href={`/moderation/assets/${event.assetId}`}
      className="font-medium text-neutral-200 hover:underline"
    >
      {event.name}
      {event.detail ? <span className="text-neutral-500"> {event.detail}</span> : null}
    </Link>
  );
}

const ROW = "flex flex-col gap-1 rounded-md border border-neutral-800 bg-neutral-950 p-4 text-sm";

function EventRow({ event }: { event: TrailEvent }) {
  return (
    <li className={ROW}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <PictureLink event={event} />
        <span className="text-xs text-neutral-600">{when(event.at)}</span>
      </div>
      <p
        className={
          event.rejectionKind === "safety" ? "text-red-400" : "text-neutral-400"
        }
      >
        {eventLine(event)}
      </p>
      <p className="flex flex-wrap gap-x-4 text-xs">
        {event.actor ? (
          <AccountLink id={event.actor} label="by" />
        ) : (
          <span className="text-neutral-600">by nobody signed in</span>
        )}
        {event.uploader ? <AccountLink id={event.uploader} label="uploaded by" /> : null}
      </p>
    </li>
  );
}

/** What state a picture is in now, which is the asset row rather than the log,
 * and is what the button below it acts on. */
function assetState(asset: TrailAsset): string {
  if (asset.moderation !== "rejected") {
    return asset.approvalSource
      ? `${asset.moderation}, ${asset.approvalSource}`
      : asset.moderation;
  }

  return `rejected, ${asset.rejectionKind}`;
}

function AssetRow({ asset }: { asset: TrailAsset }) {
  const safety = asset.rejectionKind === "safety";

  return (
    <li className={ROW}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <PictureLink event={{ assetId: asset.id, name: asset.name, detail: asset.detail }} />
        <span className="text-xs text-neutral-600">
          {asset.origin}, {when(asset.createdAt)}
        </span>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className={safety ? "text-red-400" : "text-neutral-400"}>{assetState(asset)}</p>

        {asset.moderation !== "rejected" ? null : safety ? (
          // No button, and the reason said out loud. A safety rejection is not
          // a judgement call to be revisited, and `public.return_asset` and the
          // table both refuse one whatever this page renders.
          <span className="text-xs text-red-400">Final</span>
        ) : (
          <form action={returnToQueue}>
            <button
              type="submit"
              name="asset"
              value={asset.id}
              className="rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
            >
              Return to the queue
              <span className="sr-only"> {asset.name}</span>
            </button>
          </form>
        )}
      </div>
    </li>
  );
}

function Empty({ children }: { children: string }) {
  return (
    <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
      {children}
    </p>
  );
}

function uploadedLine(trail: AccountTrail): string {
  if (trail.uploadedTotal > trail.uploaded.length) {
    return `The ${trail.uploaded.length} newest of ${trail.uploadedTotal} pictures this account uploaded.`;
  }
  return trail.uploadedTotal === 1
    ? "One picture this account uploaded."
    : `${trail.uploadedTotal} pictures this account uploaded.`;
}

export default async function Trail({ searchParams }: PageProps<"/moderation/trail">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as the other two moderation pages: whether
  // this page exists is not something a stranger needs to learn.
  if (!allowed) notFound();

  const { account } = await searchParams;
  const focus = typeof account === "string" && isUuid(account) ? account : null;

  const admin = createAdminClient();
  const trail = focus ? await fetchAccountTrail(admin, focus) : null;
  const recent = trail ? [] : await fetchRecentEvents(admin);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="pictures" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-1">
          <ModerationCrumb parent="pictures">Trail</ModerationCrumb>
          <h1 className="text-3xl font-semibold tracking-tight">Trail</h1>
        </div>

        {trail && focus ? (
          <>
            <div className="flex flex-wrap items-baseline justify-between gap-3">
              <p className="text-sm text-neutral-400">
                Everything account <span className="font-mono text-neutral-200">{focus}</span> is
                behind, as the uploader or as the one who decided.
              </p>
              <Link
                href="/moderation/trail"
                className="text-sm text-neutral-500 transition-colors hover:text-neutral-300"
              >
                Everything instead
              </Link>
            </div>

            <h2 className="text-sm text-neutral-500">{uploadedLine(trail)}</h2>
            {trail.uploaded.length === 0 ? (
              <Empty>This account has uploaded nothing.</Empty>
            ) : (
              <ul className="flex flex-col gap-3">
                {trail.uploaded.map((asset) => (
                  <AssetRow key={asset.id} asset={asset} />
                ))}
              </ul>
            )}

            <h2 className="text-sm text-neutral-500">And what it did, newest first.</h2>
            {trail.events.length === 0 ? (
              <Empty>Nothing this account did has changed what anybody can see.</Empty>
            ) : (
              <ul className="flex flex-col gap-3">
                {trail.events.map((event) => (
                  <EventRow key={event.id} event={event} />
                ))}
              </ul>
            )}
          </>
        ) : (
          <>
            <p className="text-sm text-neutral-400">
              Every decision that changed what the public can see, newest first, up to{" "}
              {TRAIL_PAGE_SIZE}. Follow an account to see everything behind it.
            </p>
            {recent.length === 0 ? (
              <Empty>Nothing has been decided yet.</Empty>
            ) : (
              <ul className="flex flex-col gap-3">
                {recent.map((event) => (
                  <EventRow key={event.id} event={event} />
                ))}
              </ul>
            )}
          </>
        )}
      </div>
    </main>
  );
}
