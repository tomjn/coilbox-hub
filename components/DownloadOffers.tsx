"use client";

import { useActionState } from "react";
import { actOnDownloadOffer } from "@/app/games/actions";
import type { DownloadOffer } from "@/lib/games/offers";
import type { GameFormState } from "@/lib/games/formState";

/**
 * The download sources coilbox has offered and nobody has decided yet (#408).
 *
 * Shared by the two places a person can decide one: a game's edit page, where
 * the owner is already standing when they would otherwise type the same thing
 * in by hand, and `/moderation/games`, where a moderator finds the ones nobody
 * owns. Most games have no owner, so without the second place almost every
 * offer would sit forever.
 *
 * ## Why this is held apart from the list below it
 *
 * An offer is a stranger's suggestion. Drawing it in the download sources form
 * would say the game has a source it does not have, and a person skimming their
 * own edit page would have no way to tell what they wrote from what somebody
 * else proposed. It sits above the form, named as an offer, and moves into the
 * list only when somebody accepts it.
 *
 * Accepting appends. There is no control for where in the order it lands,
 * because the order is the fallback chain and reordering it is editing the
 * list, which is the form directly below.
 */

/** How a source reads on the page. A rapid tag and a repo path are the strings
 *  themselves, so they are shown as they are stored rather than dressed as
 *  links: a rapid tag is not an address, and a repo shown as one would invite
 *  somebody to check the wrong thing. */
function describe(offer: DownloadOffer): string {
  if (offer.kind === "github") {
    return offer.asset ? `${offer.value}, picking ${offer.asset}` : offer.value;
  }
  if (offer.kind === "url") {
    return offer.filename ? `${offer.value}, saved as ${offer.filename}` : offer.value;
  }
  return offer.value;
}

const KIND_LABELS = {
  rapid: "Rapid tag",
  url: "Address",
  github: "GitHub repo",
} as const;

const ACCEPT =
  "rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-100 transition-colors hover:border-neutral-500 active:border-neutral-400 disabled:opacity-60";
const DECLINE =
  "rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 disabled:opacity-60";

/**
 * One offer's two buttons, in one form.
 *
 * Both are submits on a single form sharing the `accept` name, the way the
 * ownership queue's approve and decline are, because they are one decision with
 * two answers rather than two things you can do. That also means one
 * `useActionState`, so the sentence that comes back sits under the offer it
 * belongs to.
 */
function OfferDecision({ offer, shortname }: { offer: DownloadOffer; shortname: string }) {
  const [state, formAction, pending] = useActionState<GameFormState | null, FormData>(
    actOnDownloadOffer,
    null,
  );

  return (
    <form action={formAction} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="offer" value={offer.id} />
      <input type="hidden" name="shortname" value={shortname} />
      <button type="submit" name="accept" value="true" disabled={pending} className={ACCEPT}>
        {pending ? "Saving…" : "Add it"}
      </button>
      <button type="submit" name="accept" value="false" disabled={pending} className={DECLINE}>
        Turn it down
      </button>
      {state ? (
        <p
          role={state.ok ? "status" : "alert"}
          className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}
        >
          {state.message}
        </p>
      ) : null}
    </form>
  );
}

/**
 * `showGame` draws the shortname on each row, for the queue that spans every
 * game. The edit page leaves it off, since every offer there belongs to the
 * game whose page it is.
 */
export function DownloadOffers({
  offers,
  showGame = false,
}: {
  offers: DownloadOffer[];
  showGame?: boolean;
}) {
  if (offers.length === 0) return null;

  return (
    <ul className="flex flex-col gap-3">
      {offers.map((offer) => (
        <li
          key={offer.id}
          className="flex flex-col gap-2 rounded-md border border-neutral-900 p-4"
        >
          <div className="flex flex-col gap-1">
            <p className="text-xs uppercase tracking-wide text-neutral-500">
              {showGame ? `${offer.shortname} · ` : ""}
              {KIND_LABELS[offer.kind]}
            </p>
            <p className="break-all font-mono text-sm text-neutral-200">{describe(offer)}</p>
          </div>
          <OfferDecision offer={offer} shortname={offer.shortname} />
        </li>
      ))}
    </ul>
  );
}
