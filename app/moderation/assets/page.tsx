import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import { fetchPictureQueue } from "@/lib/assets/queue";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import { approveSelected, rejectOne } from "./actions";

/**
 * The pictures waiting for review, as a contact sheet (issue #114).
 *
 * A grid rather than the card at a time flow the reports page uses, and the
 * difference is the corpus rather than taste. These are terrain and game
 * structures with essentially no images of people in them, the exception being a
 * novelty map at something like one in a thousand, so the reviewer is pattern
 * matching against "this is a game asset" rather than making a judgement call
 * per picture. Anything resembling a photograph of a person stands out of a grid
 * instead of blending into it. A card each would make an easy job tedious enough
 * to stop getting done, which is how a moderation queue actually fails, and a
 * queue nobody reads is the same as no queue at all.
 *
 * The low base rate is why the grid is cheap, not a reason to look less hard.
 * The threat is adversarial: somebody deliberately abusing a modified client is
 * not drawn from the same distribution as ordinary uploads, so the rate at which
 * ordinary uploads turn out fine says nothing about them.
 *
 * ## What the browser is given
 *
 * A row id per picture and nothing else. The thumbnails come from
 * `app/moderation/assets/[id]`, which checks `is_moderator()` on every request,
 * so a pending object's path never reaches a browser and a URL taken off this
 * page is worth nothing to anybody else. `lib/assets/queue.ts` carries the rest
 * of the reasoning, including why the queue is not read through a policy.
 *
 * Nothing here is a client component and the page ships no JavaScript of its
 * own. Every tile is a checkbox in one form, which is what makes "approve all of
 * these" a single submission.
 */

// Fainter than the reports page, which sits at 0.08. The grid fills the width
// with pictures, and a backdrop competing with them would make an anomaly
// harder to spot rather than easier.
const BACKDROP_STRENGTH = 0.05;

const BUTTON =
  "rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition-colors hover:border-neutral-500 hover:text-white";

/** Unticked reads as grey and faint, ticked as full colour and framed, so what
 * is about to be approved is legible across a whole sheet at a glance rather
 * than one caption at a time. Colour is the strongest signal available here and
 * these are colour pictures of terrain, so removing it is unmissable. */
const TILE =
  "flex cursor-pointer flex-col gap-2 rounded-md border border-neutral-800 bg-neutral-950 p-2 opacity-40 grayscale transition-all hover:border-neutral-600 has-checked:border-neutral-400 has-checked:opacity-100 has-checked:grayscale-0 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-neutral-300";

/** The two rejections a tile offers (issue #115). Quiet for the everyday one
 * and red for the one that cannot be undone, so the two are told apart before
 * the label is read rather than after. */
const REJECT =
  "rounded-sm bg-black/70 px-2 py-1.5 text-xs text-neutral-400 transition-colors hover:bg-black hover:text-neutral-100 focus-visible:text-neutral-100";

const REJECT_UNSAFE =
  "rounded-sm bg-black/70 px-2 py-1.5 text-xs text-red-500 transition-colors hover:bg-black hover:text-red-300 focus-visible:text-red-300";

/**
 * How a picture somebody has reported different source bytes for is marked
 * (issue #116).
 *
 * The ring is on the list item and the note sits under the label rather than
 * over it, and both are outside the label on purpose. A tile is greyscaled
 * while it is unticked, a flagged tile starts unticked, and an amber mark
 * inside the label would therefore render grey at exactly the moment it most
 * needs to be seen. Under it rather than over it because the corners are spoken
 * for: the reject buttons sit top right and a badge opposite them collides on a
 * narrow tile.
 *
 * Amber rather than red. This is not a rejection and not an accusation, it is
 * one picture in the sheet worth a second of attention.
 */
const FLAGGED = "relative flex flex-col gap-1 rounded-md p-0.5 ring-2 ring-amber-500/80";

const FLAG =
  "rounded-sm bg-amber-950/80 px-1.5 py-1 text-[0.65rem] leading-tight font-medium text-amber-300";

function waitingLine(shown: number, total: number): string {
  if (total > shown) return `The ${shown} oldest of ${total} waiting.`;
  return total === 1 ? "One picture waiting." : `${total} pictures waiting.`;
}

export default async function PictureQueue() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as the reports page: whether this page exists
  // is not something a stranger needs to learn.
  if (!allowed) notFound();

  const { waiting, total } = await fetchPictureQueue(createAdminClient());

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="pictures" />
      <div className="relative z-10 mx-auto flex w-full max-w-6xl flex-col gap-6 px-6 py-12">
        {/* The trail is this queue's own page rather than a section of its own,
            so it hangs off the heading here and names Pictures on the way back.
            Beside the heading rather than opposite it, because this page is
            wide enough that the far edge is a different part of the screen. */}
        <div className="flex flex-wrap items-baseline gap-4">
          <h1 className="text-3xl font-semibold tracking-tight">Pictures</h1>
          <Link
            href="/moderation/trail"
            className="text-sm text-neutral-500 transition-colors hover:text-neutral-300"
          >
            Trail
          </Link>
        </div>

        {waiting.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
            Nothing waiting.
          </p>
        ) : (
          <>
            {/* The reject buttons down in the grid belong to these forms rather
                than to the one around them, which is what the `form` attribute
                is for. A form cannot be nested inside another, and rejecting one
                picture must not carry every other picture's tick with it.

                One form per kind (#115), because a submit button carries one
                name and one value and the tile's button has to spend those on
                saying which picture. The kind rides along as a hidden field, so
                which button was pressed is which form was submitted. */}
            <form id="reject-editorial" action={rejectOne}>
              <input type="hidden" name="kind" value="editorial" />
            </form>
            <form id="reject-safety" action={rejectOne}>
              <input type="hidden" name="kind" value="safety" />
            </form>

            <form action={approveSelected} className="flex flex-col gap-5">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <p className="text-sm text-neutral-400">
                  {waitingLine(waiting.length, total)} Untick anything you are not
                  sure about. Reject what does not belong here, and mark as unsafe
                  anything illegal or seriously harmful, which is final. Anything
                  ringed in amber came out of an archive that produced different
                  bytes for somebody else, and starts unticked.
                </p>
                <button type="submit" className={BUTTON}>
                  Approve the ticked
                </button>
              </div>

              <ul className="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-3">
                {waiting.map((picture) => (
                  <li key={picture.id} className={picture.sourceConflict ? FLAGGED : "relative"}>
                    <label className={TILE}>
                      {/* Unticked when the archive disagrees with itself, which
                          is the whole of what the flag does. Not a gate: it is
                          one click to tick it and it goes out with the rest of
                          the batch. What it stops is the picture nobody looked
                          at riding the approve-everything button. */}
                      <input
                        type="checkbox"
                        name="asset"
                        value={picture.id}
                        defaultChecked={!picture.sourceConflict}
                        className="sr-only"
                      />
                      {/* Not next/image. Hobby allows around 5,000
                          transformations a month, and one visit to this page
                          would spend a twentieth of them on thumbnails the
                          pipeline has already capped at 512px on their longest
                          edge. The same reasoning as components/MapMinimap.tsx.
                          Lazy, so a sheet the reviewer scrolls half of fetches
                          half the bytes from the store. */}
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={`/moderation/assets/${picture.id}`}
                        alt={`${picture.name}, ${picture.detail}`}
                        width={picture.width}
                        height={picture.height}
                        loading="lazy"
                        decoding="async"
                        className="aspect-square w-full rounded-sm bg-black object-contain"
                      />
                      <span className="flex flex-col gap-0.5 text-xs leading-tight">
                        <span className="truncate text-neutral-300" title={picture.name}>
                          {picture.name}
                        </span>
                        <span className="truncate text-neutral-500">{picture.detail}</span>
                        <span className="truncate text-neutral-600" title={picture.sourceArchive}>
                          {picture.origin}, {Math.round(picture.bytes / 1024)} kB
                        </span>
                        {/* Inside the label, so the mark is part of the
                            checkbox's own name. The ring and the note below are
                            both outside it and neither is announced, which
                            would leave a tile that is unticked for no stated
                            reason. */}
                        {picture.sourceConflict && (
                          <span className="sr-only">
                            {`Another upload reported different source bytes out of the same archive, ${picture.sourceArchive}. Unticked until you have looked at it.`}
                          </span>
                        )}
                      </span>
                    </label>
                    {picture.sourceConflict && (
                      <p
                        className={FLAG}
                        title={`Another upload reported different source bytes out of ${picture.sourceArchive}. Either a modified client or a corrupted install.`}
                      >
                        Same archive, different bytes
                      </p>
                    )}
                    <div className="absolute top-3 right-3 flex flex-col items-end gap-1">
                      <button
                        type="submit"
                        form="reject-editorial"
                        name="asset"
                        value={picture.id}
                        title="Out of scope, the wrong game, a duplicate or junk. Can be put back."
                        className={REJECT}
                      >
                        Reject
                        <span className="sr-only"> {picture.name} as not belonging here</span>
                      </button>
                      <button
                        type="submit"
                        form="reject-safety"
                        name="asset"
                        value={picture.id}
                        title="Illegal or seriously harmful. Nothing in the hub can undo this."
                        className={REJECT_UNSAFE}
                      >
                        Unsafe
                        <span className="sr-only">
                          {" "}
                          {picture.name} on safety grounds, which cannot be undone
                        </span>
                      </button>
                    </div>
                  </li>
                ))}
              </ul>

              {/* Again at the foot, because a full sheet is longer than a screen
                  and scrolling back to the top to approve is the kind of friction
                  that stops the job getting done. */}
              <div>
                <button type="submit" className={BUTTON}>
                  Approve the ticked
                </button>
              </div>
            </form>
          </>
        )}
      </div>
    </main>
  );
}
