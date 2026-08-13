import { diamond, palette, round, type Palette } from "./palette";

/**
 * The backdrop drawings this site uses, all but the last a hand copy of one
 * illustration from Coilbox's `DRAWINGS` registry in `src/home/bundledArt.ts`
 * (tomjn/coilbox, around line 1237 at the time of copying). That file draws 31
 * tool illustrations from a shared renderer and palette. This only copies the
 * ones this site actually puts on a page, alongside the renderer and palette
 * they share (`CoilArt.tsx` and `palette.ts`) rather than duplicating either
 * per drawing. `blueprint` at the foot is drawn here instead, because that
 * registry has nothing to copy for the kind.
 *
 * Every copy here is a copy, not a shared dependency, the same relationship
 * `CoilLogo.tsx` has to the app icon: nothing keeps these in sync with
 * upstream, unlike `lib/container` and `lib/conquest`, which
 * `scripts/sync-vendor.ts` vendors because drift there is a silent correctness
 * bug. Drift here is only
 * cosmetic, and copying was the deliberate choice over vendoring: these
 * drawings need edits vendoring would forbid (see below), and there is no
 * shared repository to draw a vendored copy from in the first place.
 *
 * Two edits are made to every drawing on the way in:
 *
 * - Coilbox's renderer paints a full-canvas `fieldTop`/`fieldFoot` gradient
 *   behind every card's pools and subject: a panel background wash, authored
 *   for a card that needs its own background. That is wrong over a page as a
 *   backdrop, which is why `paletteFor` in `palette.ts` does not even produce
 *   those two colours and `CoilArt.tsx` never paints such a rect. None of the
 *   `paint` functions below carry the wash themselves, because it was always
 *   the renderer's to paint, not theirs, so there is nothing to strip out of
 *   any one of them.
 * - Opacities are scaled by a `strength` the caller supplies (see
 *   `CoilArt.tsx`), because Coilbox tunes every one of these for a card that
 *   sits in a grid of other muted UI chrome next to its own label. Full width
 *   behind running text and buttons, that tuning is too strong, the same
 *   finding PR #61 and then this backdrop treatment made twice for `hub`.
 *
 * `viewHeight` crops the visible window to a drawing's own content, the way
 * `hub`'s did before this file existed: every drawing here leaves room at the
 * foot of its 320x200 canvas for the label band `cardShell.ts` documents,
 * which a card needs and a full-bleed backdrop does not. Left at the full 200
 * where a drawing does not leave much of a gap there to begin with.
 */

const WIDTH = 320;

interface Pool {
  cx: number;
  cy: number;
  r: number;
  opacity: number;
}

export interface Drawing {
  /** Namespaces this drawing's gradient ids, so more than one can render on
   * the same page without colliding. */
  id: string;
  ariaLabel: string;
  /** Cropped viewBox height. See the module comment. */
  viewHeight: number;
  pools: readonly Pool[];
  /** Builds the subject's markup. `strength` scales every opacity down from
   * Coilbox's own card tuning, matching `paintHub`'s `op` helper before this
   * file existed. */
  paint(p: Palette, strength: number): string;
}

/**
 * A row of other people's things above a line, and one of them crossing it
 * onto a shelf that already holds two. Copied from `hub` (tool id
 * `hub.browse`), around line 821. Used on the landing page: it is the one
 * drawing whose subject is an assortment rather than one kind of thing, which
 * suits the page that is not committed to any one of them either.
 *
 * The lowest shape (the shelf line) ends at y=130, so `viewHeight` crops to
 * 148, leaving the label safe-zone below it out of frame the way it was
 * before this module existed.
 */
export const hub: Drawing = {
  id: "hub",
  ariaLabel:
    "Five shapes above a dashed line, one crossing it onto a shelf that already holds two",
  viewHeight: 148,
  pools: [
    { cx: 160, cy: 34, r: 152, opacity: 0.16 },
    { cx: 160, cy: 108, r: 92, opacity: 0.13 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    // Shared out there. The hexagon is the shape the setup-pack drawing below
    // is built from, so the two read as the same object in two places.
    const shared =
      '<polygon points="46,32 60,40 60,56 46,64 32,56 32,40"/>' +
      '<rect x="92" y="20" width="34" height="26" rx="4"/>' +
      '<circle cx="166" cy="46" r="16"/>' +
      diamond(222, 30, 16) +
      '<rect x="262" y="30" width="28" height="28" rx="4"/>';
    // Already yours, so the arriving one is joining a shelf rather than
    // landing on an empty card.
    const held =
      '<rect x="96" y="98" width="28" height="24" rx="3"/>' +
      '<circle cx="212" cy="110" r="12"/>';
    return (
      `<g fill="${p.line}" fill-opacity="${op(0.5)}" stroke="${p.faint}" stroke-width="1.5" stroke-opacity="${op(0.65)}">${shared}</g>` +
      `<g fill="none" stroke="${p.faint}" stroke-width="1.5" stroke-opacity="${op(0.45)}" stroke-dasharray="6 9">` +
      '<path d="M-10 78 L330 78"/>' +
      "</g>" +
      `<g fill="${p.line}" fill-opacity="${op(0.55)}" stroke="${p.faint}" stroke-width="1.2" stroke-opacity="${op(0.65)}">${held}</g>` +
      `<g fill="none" stroke="${p.spark}" stroke-width="2" stroke-opacity="${op(0.6)}" stroke-linecap="round" stroke-linejoin="round">` +
      '<path d="M222 50 Q216 82 176 94"/>' +
      '<path d="M188 84 L176 94 L191 96"/>' +
      "</g>" +
      `<g fill="${p.spark}" fill-opacity="${op(0.8)}">${diamond(160, 107, 15)}</g>` +
      `<rect x="84" y="126" width="152" height="4" rx="2" fill="${p.faint}" fill-opacity="${op(0.5)}"/>`
    );
  },
};

/**
 * A package falling through a stack of chevrons into a tray that already
 * holds two. Copied from `downloads` (tool id `downloads.browse`), around
 * line 456. Used on the item page: importing an item is downloading it into
 * Coilbox, so a package on its way into a tray that already holds others is
 * one honest, kind-agnostic image for "the thing you are looking at", ahead
 * of the per-kind treatment issue #68 gives this page later.
 *
 * Opacities here are Coilbox's own card values, not boosted the way `hub`'s
 * were by PR #61. There is no prior full-strength use of this drawing to
 * match, so `strength` scales the card tuning directly.
 *
 * Content runs from the arrows at y=40 to the tray's feet at y=172, so
 * `viewHeight` crops to 184, leaving a little of the label safe-zone out of
 * frame without cutting the second pool's centre (y=168) off screen.
 */
export const downloads: Drawing = {
  id: "downloads",
  ariaLabel:
    "A package falling through chevrons into a tray that already holds two",
  viewHeight: 184,
  pools: [
    { cx: 160, cy: 44, r: 118, opacity: 0.18 },
    { cx: 160, cy: 168, r: 104, opacity: 0.11 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const arrows = [40, 62, 84]
      .map(
        (y, i) =>
          `<path d="M124 ${y} L160 ${y + 22} L196 ${y}" stroke-opacity="${op(round(0.1 + i * 0.07))}"/>`,
      )
      .join("");
    const landed = [
      [126, 148],
      [154, 148],
    ]
      .map(
        ([x, y]) =>
          `<rect x="${x}" y="${y}" width="22" height="18" rx="3" fill="${p.line}" fill-opacity="${op(0.2)}"/>`,
      )
      .join("");
    return (
      `<g fill="none" stroke="${p.line}" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">` +
      arrows +
      "</g>" +
      `<rect x="146" y="98" width="28" height="24" rx="4" fill="${p.line}" fill-opacity="${op(0.26)}"/>` +
      `<rect x="146" y="98" width="28" height="24" rx="4" fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.42)}"/>` +
      landed +
      `<g fill="none" stroke="${p.faint}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="${op(0.3)}">` +
      '<path d="M104 140 L104 172 L216 172 L216 140"/>' +
      "</g>" +
      `<circle cx="160" cy="110" r="3" fill="${p.spark}" fill-opacity="${op(0.85)}"/>`
    );
  },
};

/**
 * One hexagonal shell holding several different things. Copied from
 * `setupPacks` (tool id `content.setupPacks`), around line 746. Used on the
 * publish page: whatever kind of thing is being sent in, publishing is
 * packaging it up and handing it over, and a shell holding an assortment is
 * the most literal picture of "a package" this site's drawings have.
 *
 * Content already runs nearly the full canvas (the outer hexagon spans y=12
 * to y=168), so `viewHeight` is left at the full 200 rather than cropped.
 */
export const setupPacks: Drawing = {
  id: "setup-packs",
  ariaLabel: "A hexagonal shell holding an assortment of shapes",
  viewHeight: 200,
  pools: [
    { cx: 160, cy: 78, r: 104, opacity: 0.22 },
    { cx: 160, cy: 78, r: 190, opacity: 0.08 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="2" stroke-opacity="${op(0.34)}">` +
      '<polygon points="160,12 246,58 246,122 160,168 74,122 74,58"/>' +
      "</g>" +
      `<g fill="none" stroke="${p.line}" stroke-width="1.5" stroke-opacity="${op(0.3)}">` +
      '<polygon points="160,36 224,70 224,118 160,152 96,118 96,70"/>' +
      "</g>" +
      `<circle cx="132" cy="76" r="11" fill="${p.line}" fill-opacity="${op(0.3)}"/>` +
      `<rect x="176" y="64" width="24" height="24" rx="4" fill="${p.line}" fill-opacity="${op(0.26)}"/>` +
      `<g fill="${p.spark}" fill-opacity="${op(0.7)}">${diamond(160, 118, 12)}</g>`
    );
  },
};

/**
 * A shelf of games, one pulled forward. Copied from `games` (tool id
 * `content.games`), around line 689. Used on the account page. "What you
 * have, not what you could get" is the original's own doc comment, and an
 * account page listing everything a person has published is exactly that
 * shelf.
 *
 * Content ends at the shelf line, y=132, so `viewHeight` crops to 150, the
 * same label safe-zone trim `hub` needed.
 */
export const games: Drawing = {
  id: "games",
  ariaLabel: "A shelf of cases, one pulled forward and lit",
  viewHeight: 150,
  pools: [
    { cx: 160, cy: 74, r: 134, opacity: 0.17 },
    { cx: 44, cy: 138, r: 84, opacity: 0.09 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const cases = [0, 1, 2, 3, 4]
      .map((i) => {
        const x = 46 + i * 40;
        const h = 82 + (i % 3) * 8;
        return (
          `<rect x="${x}" y="${round(128 - h)}" width="30" height="${h}" rx="3" fill="${p.line}" fill-opacity="${op(0.16)}"/>` +
          `<rect x="${x}" y="${round(128 - h)}" width="30" height="${h}" rx="3" fill="none" stroke="${p.faint}" stroke-width="1.2" stroke-opacity="${op(0.34)}"/>`
        );
      })
      .join("");
    return (
      cases +
      `<rect x="222" y="36" width="34" height="94" rx="4" fill="${p.line}" fill-opacity="${op(0.24)}"/>` +
      `<rect x="222" y="36" width="34" height="94" rx="4" fill="none" stroke="${p.spark}" stroke-width="1.8" stroke-opacity="${op(0.55)}"/>` +
      `<rect x="228" y="48" width="22" height="3" rx="1.5" fill="${p.spark}" fill-opacity="${op(0.6)}"/>` +
      `<rect x="228" y="56" width="14" height="3" rx="1.5" fill="${p.spark}" fill-opacity="${op(0.4)}"/>` +
      `<rect x="30" y="128" width="260" height="4" rx="2" fill="${p.faint}" fill-opacity="${op(0.32)}"/>`
    );
  },
};

/**
 * Sealed drums stacked on a pallet. Copied from `archives` (tool id
 * `content.archives`), around line 717. Used on the moderation page: acting
 * on a report takes an item out of circulation, and "an archive is a
 * container, not a screen", the original's own doc comment, reads as the
 * quiet, administrative register that page is written in.
 *
 * Content ends at the pallet, y=145, so `viewHeight` crops to 160.
 */
export const archives: Drawing = {
  id: "archives",
  ariaLabel: "Sealed drums stacked on a pallet",
  viewHeight: 160,
  pools: [
    { cx: 160, cy: 70, r: 118, opacity: 0.18 },
    { cx: 268, cy: 132, r: 84, opacity: 0.09 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    // Coilbox's own `drum` leaves the two side paths below without a
    // stroke-opacity, so they paint at the SVG default of fully opaque. At
    // card size against `p.faint`/`p.line`'s own restraint that goes
    // unnoticed. Scaled up as a full-bleed backdrop with everything else
    // dimmed by `strength`, two full-opacity vertical lines read as a stray
    // UI seam rather than part of the drawing, so they are given `op(o)`
    // here, matching the ellipse each side belongs to, rather than left to
    // inherit.
    const drum = (cx: number, cy: number, rx: number, ry: number, o: number) =>
      `<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="${ry}" stroke-opacity="${op(o)}"/>` +
      `<path d="M${cx - rx} ${cy} L${cx - rx} ${cy + 28}" stroke-opacity="${op(o)}"/>` +
      `<path d="M${cx + rx} ${cy} L${cx + rx} ${cy + 28}" stroke-opacity="${op(o)}"/>` +
      `<ellipse cx="${cx}" cy="${cy + 28}" rx="${rx}" ry="${ry}" stroke-opacity="${op(round(o * 0.6))}"/>`;
    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="1.8">` +
      drum(160, 26, 52, 15, 0.34) +
      drum(160, 62, 52, 15, 0.4) +
      "</g>" +
      `<g fill="none" stroke="${p.line}" stroke-width="2">` +
      drum(160, 98, 52, 15, 0.5) +
      "</g>" +
      `<rect x="90" y="140" width="140" height="5" rx="2.5" fill="${p.faint}" fill-opacity="${op(0.34)}"/>` +
      `<circle cx="160" cy="98" r="5" fill="${p.spark}" fill-opacity="${op(0.8)}"/>` +
      `<g fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.35)}">` +
      '<path d="M132 126 L188 126"/>' +
      "</g>"
    );
  },
};

/**
 * Two wedges of chevrons facing off across contoured ridge lines, meeting at
 * a dashed front line. Copied from `skirmish` (tool id `play.skirmish`),
 * around line 122. Used on the item page for a preset (issue #68): a preset
 * is two sides placed on a map and set to play, which two wedges over terrain
 * shows more literally than either of the other subjects on this page.
 *
 * Checked for the `archives` bug (coilbox#1382): every chevron and ridge here
 * either carries its own `stroke-opacity` or sits in a group that sets one,
 * so nothing is left at the SVG default.
 *
 * The lowest ridge line reaches y=178, close enough to the canvas foot that
 * cropping it would trim almost nothing, so `viewHeight` is left at the full
 * 200, the same call `setupPacks` above made for the same reason.
 */
export const skirmish: Drawing = {
  id: "skirmish",
  ariaLabel: "Two wedges of chevrons facing each other across contoured ground",
  viewHeight: 200,
  pools: [
    { cx: 72, cy: 108, r: 130, opacity: 0.16 },
    { cx: 252, cy: 92, r: 120, opacity: 0.14 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const ridge = (d: string, o: number) =>
      `<path d="${d}" stroke-opacity="${op(o)}"/>`;
    const chevron = (x: number, y: number, dir: 1 | -1) =>
      `<path d="M${x - dir * 4} ${y - 5} L${x + dir * 4} ${y} L${x - dir * 4} ${y + 5}"/>`;
    const wedge = (tip: number, dir: 1 | -1) =>
      [[0], [-16, 16], [-32, 0, 32]]
        .flatMap((rank, col) =>
          rank.map((dy) => chevron(tip - dir * col * 18, 100 + dy, dir)),
        )
        .join("");
    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="1.5">` +
      ridge("M-10 58 Q 74 40 146 54 T 330 44", 0.24) +
      ridge("M-10 148 Q 66 130 128 146 T 250 138 T 330 150", 0.3) +
      ridge("M-10 178 Q 88 162 168 174 T 330 166", 0.2) +
      "</g>" +
      `<g fill="none" stroke="${p.line}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="${op(0.42)}">` +
      wedge(118, 1) +
      wedge(202, -1) +
      "</g>" +
      `<g fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.32)}" stroke-dasharray="7 9">` +
      '<path d="M160 40 L160 164"/>' +
      "</g>" +
      `<circle cx="160" cy="100" r="4" fill="${p.spark}" fill-opacity="${op(0.7)}"/>`
    );
  },
};

/**
 * A reticle over a perspective grid receding to a horizon. Copied from
 * `scenarios` (tool id `scenario.list`), around line 260. Used on the item
 * page for a scenario (issue #68): a scenario is one objective on one piece
 * of ground, so an aimed shot over a place reads truer than a list would.
 *
 * Checked for the `archives` bug (coilbox#1382): every lane, band, ring and
 * tick here sits in a group that sets its own `stroke-opacity`, so nothing is
 * left at the SVG default.
 *
 * The perspective lanes are authored to run past the canvas foot (to y=210,
 * against a 200-tall canvas), and the last horizontal band sits at y=182, so
 * there is barely anything below frame to crop. `viewHeight` is left at the
 * full 200, the same call `setupPacks` above made for the same reason.
 */
export const scenario: Drawing = {
  id: "scenario",
  ariaLabel: "A reticle over a perspective grid receding to a horizon",
  viewHeight: 200,
  pools: [
    { cx: 198, cy: 92, r: 118, opacity: 0.19 },
    { cx: 30, cy: 190, r: 130, opacity: 0.08 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const horizon = 66;
    const lanes = [-70, 0, 70, 140, 210, 280, 350, 420]
      .map((x) => `<path d="M160 ${horizon} L${x} 210"/>`)
      .join("");
    const bands = [96, 118, 146, 182]
      .map((y) => `<path d="M-10 ${y} L330 ${y}"/>`)
      .join("");
    const ticks = [
      "M198 30 L198 48",
      "M198 136 L198 154",
      "M136 92 L154 92",
      "M242 92 L260 92",
    ]
      .map((d) => `<path d="${d}"/>`)
      .join("");
    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="1" stroke-opacity="${op(0.22)}">` +
      lanes +
      bands +
      "</g>" +
      `<g fill="none" stroke="${p.line}" stroke-width="1.5" stroke-opacity="${op(0.45)}">` +
      '<circle cx="198" cy="92" r="54"/>' +
      '<circle cx="198" cy="92" r="34"/>' +
      "</g>" +
      `<g fill="none" stroke="${p.spark}" stroke-width="2" stroke-opacity="${op(0.6)}" stroke-linecap="round">` +
      ticks +
      "</g>" +
      `<circle cx="198" cy="92" r="5" fill="${p.spark}" fill-opacity="${op(0.85)}"/>`
    );
  },
};

/** mulberry32, copied from `src/conquest/rng.ts` (tomjn/coilbox) rather than
 * imported: `conquest` below is the only drawing that needs a seeded PRNG,
 * and this file copies drawings, not app modules. */
function mulberry32(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * A galaxy: a lit core, orbital sweeps, a starfield from a fixed seed.
 * Copied from `conquest` (tool id `conquest.list`), around line 306. Used on
 * the item page for a conquest challenge (issue #68): conquest's whole
 * subject is a galaxy, so this is the one drawing that is not a stand-in for
 * its kind but a picture of the actual thing.
 *
 * Checked for the `archives` bug (coilbox#1382): every star, arm, flare mark
 * and the two trailing sparks here either carries its own opacity or sits in
 * a group that sets one, so nothing is left at the SVG default.
 *
 * The starfield is seeded to spread across the full 320x200 canvas rather
 * than clustering near the core, so cropping would only ever cut stars off
 * rather than trim empty space. `viewHeight` is left at the full 200.
 */
export const conquest: Drawing = {
  id: "conquest",
  ariaLabel: "A galaxy: a lit core, orbital sweeps and a starfield",
  viewHeight: 200,
  pools: [
    { cx: 206, cy: 88, r: 62, opacity: 0.3 },
    { cx: 206, cy: 88, r: 150, opacity: 0.12 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const rand = mulberry32(0x5ca1ab1e);
    const stars = Array.from({ length: 46 }, () => {
      const x = round(rand() * WIDTH);
      const y = round(rand() * 200);
      const r = round(0.6 + rand() * 1.3);
      const o = round(0.2 + rand() * 0.45);
      return `<circle cx="${x}" cy="${y}" r="${r}" fill-opacity="${op(o)}"/>`;
    }).join("");
    const arms = [
      [128, 46],
      [96, 34],
      [62, 22],
    ]
      .map(
        ([rx, ry], i) =>
          `<ellipse cx="206" cy="88" rx="${rx}" ry="${ry}" transform="rotate(-19 206 88)" stroke-opacity="${op(round(0.16 + i * 0.09))}"/>`,
      )
      .join("");
    const flare = [
      "M206 66 L206 110",
      "M184 88 L228 88",
      "M190 72 L222 104",
      "M222 72 L190 104",
    ]
      .map((d) => `<path d="${d}"/>`)
      .join("");
    return (
      `<g fill="${p.line}">${stars}</g>` +
      `<g fill="none" stroke="${p.line}" stroke-width="1.5">${arms}</g>` +
      `<g fill="none" stroke="${p.spark}" stroke-width="1" stroke-opacity="${op(0.3)}">${flare}</g>` +
      `<circle cx="206" cy="88" r="7" fill="${p.spark}" fill-opacity="${op(0.9)}"/>` +
      `<circle cx="86" cy="150" r="3.5" fill="${p.spark}" fill-opacity="${op(0.55)}"/>` +
      `<circle cx="46" cy="62" r="2.5" fill="${p.spark}" fill-opacity="${op(0.45)}"/>`
    );
  },
};

/**
 * A run laid out as tiers of nodes with branching routes, climbing to one lit
 * node at the top. Copied from `warpath` (tool id `runlite.list`), around
 * line 357. Used on the item page for a warpath challenge (issue #68): a
 * branching climb to one lit node is warpath's own shape, a route picked
 * through tiers of encounters, and cannot be mistaken for conquest's galaxy
 * or a campaign's single road.
 *
 * Checked for the `archives` bug (coilbox#1382): every edge and node here
 * sits in a group that sets its own `fill-opacity` or `stroke-opacity`, so
 * nothing is left at the SVG default.
 *
 * The bottom tier sits at y=170, with edges reaching a few pixels past that,
 * close enough to the canvas foot that cropping would trim almost nothing.
 * `viewHeight` is left at the full 200, the same call `setupPacks` above made
 * for the same reason. The second pool is centred at y=200, the canvas foot,
 * so it glows up into the graph from below rather than sitting inside frame.
 */
export const warpath: Drawing = {
  id: "warpath",
  ariaLabel: "Tiers of nodes with branching routes climbing to one lit node",
  viewHeight: 200,
  pools: [
    { cx: 160, cy: 74, r: 96, opacity: 0.22 },
    { cx: 160, cy: 200, r: 150, opacity: 0.1 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const tiers: readonly (readonly number[])[] = [
      [160],
      [92, 160, 228],
      [66, 124, 196, 254],
      [160],
    ];
    const y = (t: number) => 170 - t * 32;
    const edges = tiers
      .slice(0, -1)
      .flatMap((row, t) =>
        row.flatMap((x) =>
          tiers[t + 1]
            .filter((nx) => Math.abs(nx - x) < 78)
            .map((nx) => `<path d="M${x} ${y(t) - 7} L${nx} ${y(t + 1) + 7}"/>`),
        ),
      )
      .join("");
    const nodes = tiers
      .slice(0, -1)
      .flatMap((row, t) => row.map((x) => diamond(x, y(t), 6)))
      .join("");
    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="1.5" stroke-opacity="${op(0.3)}">` +
      edges +
      "</g>" +
      `<g fill="${p.line}" fill-opacity="${op(0.42)}">${nodes}</g>` +
      `<g fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.4)}">` +
      `${diamond(160, 74, 17)}` +
      "</g>" +
      `<g fill="${p.spark}" fill-opacity="${op(0.85)}">${diamond(160, 74, 9)}</g>`
    );
  },
};

/** One build square, in canvas units. Sixteen across the 320 wide canvas, ten
 * down it, so the grid meets the frame on a line rather than mid square. */
export const BLUEPRINT_PITCH = 20;

/**
 * Ground left clear on each side of a building, in canvas units.
 *
 * The same 0.1 of a square, and the same reasoning, as `BUILDING_GAP` in
 * `lib/gallery/blueprintPreview.ts`: buildings in a real base stand shoulder
 * to shoulder, and squares drawn true to size would touch and read as one
 * shape. Taken off each building rather than added between them, so the
 * layout stays on the grid.
 */
export const BLUEPRINT_GAP = 2;

/** Where one building stands: column, row, then how many squares wide and
 * deep, all in build squares. */
type Plot = readonly [number, number, number, number];

/**
 * A base: a factory, a store, a lab, a row of four solar collectors, two
 * turrets and a second store.
 *
 * The sizes are the point rather than the buildings. A footprint is the one
 * thing about a unit a blueprint carries and the hub can draw, which is the
 * whole argument `lib/gallery/blueprintPreview.ts` makes for the preview, so
 * the backdrop is squares at several sizes rather than one size repeated.
 */
const BLUEPRINT_PLOTS: readonly Plot[] = [
  [3, 1, 3, 3],
  [7, 1, 2, 2],
  [11, 1, 2, 3],
  [7, 4, 1, 1],
  [8, 4, 1, 1],
  [9, 4, 1, 1],
  [10, 4, 1, 1],
  [3, 5, 1, 1],
  [5, 5, 1, 1],
  [8, 6, 2, 2],
];

/** The square the order has not reached yet. A blueprint is a plan for ground
 * nobody has built on, so one square is still an outline. */
const BLUEPRINT_NEXT: Plot = [12, 5, 2, 2];

/** Which plots the order thread passes through, as indices into
 * `BLUEPRINT_PLOTS`. Three of ten, and the three that run one way across the
 * layout: a line through every building would be a scribble over the thing it
 * is meant to explain, and one that doubles back reads as a mistake. */
const BLUEPRINT_ORDER: readonly number[] = [0, 3, 9];

/**
 * A base laid out on a build grid, threaded in build order, with one square
 * still an outline. Drawn for this site rather than copied from Coilbox, which
 * has no blueprint illustration to copy: every other drawing in this file is a
 * hand copy of one of its tool cards, and blueprints post-date that registry.
 *
 * Used on the item page for a blueprint (issue #85). A blueprint is a layout
 * of buildings on the build grid and, when the author saved one, the order to
 * put them up in, so the backdrop is those two things and nothing else. The
 * grid runs past the frame on every side because a layout is placed on ground
 * it does not own, which is the whole point of saving one.
 *
 * `BlueprintLayout` in `components/ItemPreview.tsx` draws the item's own
 * layout in front of this, in the same shapes, so the two are kept apart by
 * weight rather than by subject: the preview's buildings are solid squares at
 * the page's own contrast, and these are hairline outlines dimmed by
 * `strength` to a fraction of that. A plan of a base, behind a base.
 *
 * Nothing here comes from the payload. The other drawings on this page are
 * per kind, not per item, and a backdrop that redrew the item would be a
 * second copy of the preview behind the first.
 *
 * Content ends at the second store's foot, y=160, so `viewHeight` crops to
 * 176. The grid is authored across the full canvas and simply runs off the
 * bottom of the crop, the way it runs off the sides.
 */
export const blueprint: Drawing = {
  id: "blueprint",
  ariaLabel:
    "A base of different sized squares on a build grid, threaded in build order to one square not yet placed",
  viewHeight: 176,
  pools: [
    { cx: 150, cy: 58, r: 128, opacity: 0.18 },
    { cx: 254, cy: 150, r: 92, opacity: 0.09 },
  ],
  paint: (p, strength) => {
    const op = (v: number) => round(v * strength);
    const at = (squares: number) => squares * BLUEPRINT_PITCH;
    const box = ([col, row, wide, deep]: Plot) => ({
      x: at(col) + BLUEPRINT_GAP,
      y: at(row) + BLUEPRINT_GAP,
      width: at(wide) - BLUEPRINT_GAP * 2,
      height: at(deep) - BLUEPRINT_GAP * 2,
    });
    const rect = (plot: Plot) => {
      const { x, y, width, height } = box(plot);
      return `<rect x="${x}" y="${y}" width="${width}" height="${height}" rx="2"`;
    };
    const middle = ([col, row, wide, deep]: Plot): [number, number] => [
      at(col + wide / 2),
      at(row + deep / 2),
    ];

    const grid =
      Array.from(
        { length: WIDTH / BLUEPRINT_PITCH + 1 },
        (_, i) => `<path d="M${at(i)} 0 L${at(i)} 200"/>`,
      ).join("") +
      Array.from(
        { length: 200 / BLUEPRINT_PITCH + 1 },
        (_, i) => `<path d="M0 ${at(i)} L${WIDTH} ${at(i)}"/>`,
      ).join("");
    const built = BLUEPRINT_PLOTS.map((plot) => `${rect(plot)}/>`).join("");
    const stops = [
      ...BLUEPRINT_ORDER.map((i) => middle(BLUEPRINT_PLOTS[i])),
      middle(BLUEPRINT_NEXT),
    ];
    const [startX, startY] = stops[0];

    return (
      `<g fill="none" stroke="${p.faint}" stroke-width="0.75" stroke-opacity="${op(0.2)}">` +
      grid +
      "</g>" +
      // Outlines, not solids. `BlueprintLayout` in `components/ItemPreview.tsx`
      // draws the item's own buildings as filled squares in front of this, so
      // the backdrop stays lines on a grid: a plan of a base, against a base.
      `<g fill="none" stroke="${p.line}" stroke-width="1.4" stroke-opacity="${op(0.5)}">` +
      built +
      "</g>" +
      `${rect(BLUEPRINT_NEXT)} fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.5)}" stroke-dasharray="5 5"/>` +
      `<g fill="none" stroke="${p.spark}" stroke-width="1.5" stroke-opacity="${op(0.6)}" stroke-linecap="round" stroke-linejoin="round">` +
      `<path d="M${stops.map(([x, y]) => `${x} ${y}`).join(" L")}" stroke-dasharray="4 7"/>` +
      "</g>" +
      `<circle cx="${startX}" cy="${startY}" r="4" fill="${p.spark}" fill-opacity="${op(0.8)}"/>`
    );
  },
};

/** Every drawing this site currently uses, keyed by `id`, for iterating in
 * tests. Not the tool id, this repo's copies stand on their own once made. */
export const ALL_DRAWINGS: readonly Drawing[] = [
  hub,
  downloads,
  setupPacks,
  games,
  archives,
  skirmish,
  scenario,
  conquest,
  warpath,
  blueprint,
];

export { WIDTH, palette };
