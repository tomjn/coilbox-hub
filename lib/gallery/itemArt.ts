import {
  blueprint,
  conquest,
  downloads,
  scenario,
  setupPacks,
  skirmish,
  warpath,
  type Drawing,
} from "@/components/art/drawings";

/**
 * Which backdrop an item page shows, and how strong. Pure and separate from
 * `app/item/[id]/page.tsx` so the mapping can be unit tested without
 * rendering anything (issue #68).
 *
 * The mapping is the one coilbox's own `DRAWINGS` registry in
 * `src/home/bundledArt.ts` uses, keyed by tool id, translated to what is
 * already on the row: `kind` picks a preset, scenario or setup-pack, and a
 * challenge needs `mode` on top of that to tell warpath from conquest.
 * A blueprint is the exception, since that registry pre-dates the kind and has
 * nothing to translate, so its drawing is one this site made (issue #85).
 *
 * `downloads`, the item page's general drawing from before this issue, is
 * the fallback: a `kind` this gallery does not carry yet (a campaign, or
 * anything a newer coilbox invents) still has to render a page, and "the
 * thing you are looking at" is honest for something this codebase cannot
 * name more specifically. A challenge with no recognised `mode` falls back
 * the same way, for the same reason.
 */
const KIND_DRAWING: Record<string, Drawing> = {
  preset: skirmish,
  scenario,
  "setup-pack": setupPacks,
  blueprint,
};

const CHALLENGE_MODE_DRAWING: Record<string, Drawing> = {
  conquest,
  warpath,
};

/**
 * Strength scales each drawing's own card-tuned opacities down for a
 * full-bleed backdrop behind running text (see `CoilArt.tsx`), and has to be
 * judged per drawing rather than set once: `setupPacks`'s pool gradient is
 * close to canvas-wide, so it washes the page out at a strength the others
 * read as faint. Chosen by eye against a real published item of each kind at
 * 375 and 1440 wide, the same way the four strengths already in this
 * codebase (`app/page.tsx`, `app/gallery/page.tsx`, `app/publish/page.tsx`,
 * `app/account/page.tsx`) were.
 */
const STRENGTH: Record<string, number> = {
  skirmish: 0.16,
  scenario: 0.09,
  // Matches `app/publish/page.tsx`'s own tuning for this same drawing: its
  // second pool gradient is close to canvas-wide, so it washes the page out
  // at a strength the others read as faint.
  "setup-packs": 0.045,
  conquest: 0.17,
  warpath: 0.08,
  downloads: 0.08,
  // Under `skirmish`, for the reason `scenario` is: both draw line work across
  // the whole canvas, a build grid here and a perspective grid there, and a
  // canvas-wide mesh reads far stronger than a subject in the middle of one.
  blueprint: 0.12,
};

export interface ItemArt {
  drawing: Drawing;
  strength: number;
}

export function itemArt(kind: string, mode?: string | null): ItemArt {
  const drawing =
    (kind === "challenge" && mode ? CHALLENGE_MODE_DRAWING[mode] : undefined) ??
    KIND_DRAWING[kind] ??
    downloads;
  return { drawing, strength: STRENGTH[drawing.id] ?? STRENGTH.downloads };
}
