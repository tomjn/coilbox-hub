/**
 * The hub illustration from the coilbox app: a row of five shapes above a
 * dashed line, one crossing it onto a shelf that already holds two. Copied
 * from the `hub` drawing in `src/home/bundledArt.ts` (around line 821) in
 * tomjn/coilbox, which renders it as SVG from a data structure rather than
 * shipping a file. This is a copy, not a shared dependency, the way
 * `CoilLogo.tsx` is a copy of the app icon: nothing here keeps it in sync
 * with upstream, unlike `lib/container`, which `scripts/sync-container.ts`
 * vendors because drift there is a silent correctness bug. Drift here is
 * only cosmetic.
 *
 * Coilbox derives every colour in the drawing from a theme colour at render
 * time, so a card takes whatever accent it is given rather than shipping
 * fixed colours. This site has no accent beyond a near-white foreground on a
 * near-black field (see `app/globals.css`), so that foreground is the colour
 * fed into the same formula below. It parses as achromatic (saturation under
 * 8%), which is coilbox's own rule for a neutral theme, so the shapes come
 * out as graphite tones on the gradient field, matching the rest of the
 * site's monochrome look rather than importing coilbox's blue.
 *
 * Coilbox authors this at 320x200 under a rule that nothing paints a flat
 * area large enough to sit under a word, because a label sits over the art
 * on its card there. No label sits over it on this page, so the composition
 * is kept exactly as coilbox draws it rather than redrawn: it depicts a
 * specific arrival (four things already offered, a fifth mid-crossing), and
 * that reads the same at any size. It is shown larger than a card only
 * because there is now room, via `className` on the element this renders.
 */

const WIDTH = 320;
const HEIGHT = 200;

/** hsl(0 0% 98.04%): the `--foreground` value in `app/globals.css`, pre-parsed
 * the way coilbox's `parseColor` would read it. */
const THEME = { h: 0, s: 0, l: 98.04 };

/** Below this saturation coilbox treats a theme as achromatic. Same number as
 * `ACHROMATIC_SATURATION` in `bundledArt.ts`. */
const ACHROMATIC_SATURATION = 8;

function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

function round(n: number): number {
  return Math.round(n * 100) / 100;
}

function hsl(h: number, s: number, l: number): string {
  const hue = round(((h % 360) + 360) % 360);
  return `hsl(${hue} ${round(clamp(s, 0, 100))}% ${round(clamp(l, 0, 100))}%)`;
}

/** A diamond as a polygon, matching coilbox's `diamond`. */
function diamond(x: number, y: number, r: number): string {
  return `<polygon points="${x},${y - r} ${x + r},${y} ${x},${y + r} ${x - r},${y}"/>`;
}

interface Palette {
  fieldTop: string;
  fieldFoot: string;
  glow: string;
  faint: string;
  line: string;
  spark: string;
}

/**
 * Coilbox's `paletteFor`, with the light-scheme ramp dropped: this site sets
 * `color-scheme: dark` and nothing else, so a mark's lightness is always its
 * dark-card value, which is what `schemeLightness(scheme, dark)` returns
 * unchanged for `scheme === "dark"`.
 */
function paletteFor(theme: typeof THEME): Palette {
  const neutral = theme.s < ACHROMATIC_SATURATION;
  const sat = neutral ? clamp(theme.s, 0, 6) : clamp(theme.s, 24, 58);
  const hue = (offset: number) => (neutral ? theme.h : theme.h + offset);
  return {
    fieldTop: hsl(hue(14), sat, 17),
    fieldFoot: hsl(hue(-10), sat * 0.65, 8),
    glow: hsl(hue(22), Math.min(sat + 14, 70), 58),
    faint: hsl(hue(0), Math.min(sat + 6, 62), 54),
    line: hsl(hue(6), Math.min(sat + 18, 72), 70),
    spark: hsl(hue(-16), neutral ? sat : Math.min(sat + 30, 82), 80),
  };
}

const palette = paletteFor(THEME);

/** Centre x, centre y, radius, peak opacity, matching coilbox's `hub.pools`. */
const POOLS: readonly (readonly [number, number, number, number])[] = [
  [160, 34, 152, 0.16],
  [160, 108, 92, 0.13],
];

/** Coilbox's `hub.paint`, unchanged. */
function paintHub(p: Palette): string {
  // Shared out there. The hexagon is the shape the setup-pack card is drawn
  // from, so the two read as the same object in two places.
  const shared =
    '<polygon points="46,32 60,40 60,56 46,64 32,56 32,40"/>' +
    '<rect x="92" y="20" width="34" height="26" rx="4"/>' +
    '<circle cx="166" cy="46" r="16"/>' +
    diamond(222, 30, 16) +
    '<rect x="262" y="30" width="28" height="28" rx="4"/>';
  // Already yours, so the arriving one is joining a shelf rather than landing
  // on an empty card.
  const held =
    '<rect x="96" y="98" width="28" height="24" rx="3"/>' +
    '<circle cx="212" cy="110" r="12"/>';
  return (
    `<g fill="${p.line}" fill-opacity="0.24" stroke="${p.faint}" stroke-width="1.5" stroke-opacity="0.4">${shared}</g>` +
    `<g fill="none" stroke="${p.faint}" stroke-width="1.5" stroke-opacity="0.3" stroke-dasharray="6 9">` +
    '<path d="M-10 78 L330 78"/>' +
    "</g>" +
    `<g fill="${p.line}" fill-opacity="0.28" stroke="${p.faint}" stroke-width="1.2" stroke-opacity="0.4">${held}</g>` +
    `<g fill="none" stroke="${p.spark}" stroke-width="2" stroke-opacity="0.5" stroke-linecap="round" stroke-linejoin="round">` +
    '<path d="M222 50 Q216 82 176 94"/>' +
    '<path d="M188 84 L176 94 L191 96"/>' +
    "</g>" +
    `<g fill="${p.spark}" fill-opacity="0.8">${diamond(160, 107, 15)}</g>` +
    `<rect x="84" y="126" width="152" height="4" rx="2" fill="${p.faint}" fill-opacity="0.32"/>`
  );
}

// Built once from fixed constants, not per request, and not from anything a
// visitor supplies. The gradient ids assume a single `HubArt` on the page.
const inner =
  "<defs>" +
  '<linearGradient id="hub-art-field" x1="0" y1="0" x2="0.4" y2="1">' +
  `<stop offset="0" stop-color="${palette.fieldTop}"/>` +
  `<stop offset="1" stop-color="${palette.fieldFoot}"/>` +
  "</linearGradient>" +
  POOLS.map(
    ([cx, cy, r, o], i) =>
      `<radialGradient id="hub-art-pool${i}" cx="${cx}" cy="${cy}" r="${r}" gradientUnits="userSpaceOnUse">` +
      `<stop offset="0" stop-color="${palette.glow}" stop-opacity="${o}"/>` +
      `<stop offset="1" stop-color="${palette.glow}" stop-opacity="0"/>` +
      "</radialGradient>",
  ).join("") +
  "</defs>" +
  `<rect width="${WIDTH}" height="${HEIGHT}" fill="url(#hub-art-field)"/>` +
  POOLS.map(
    (_, i) =>
      `<rect width="${WIDTH}" height="${HEIGHT}" fill="url(#hub-art-pool${i})"/>`,
  ).join("") +
  paintHub(palette);

export function HubArt({ className }: { className?: string }) {
  return (
    <svg
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      role="img"
      aria-label="Five shapes above a dashed line, one crossing it onto a shelf that already holds two"
      className={className}
      // Static markup built above from fixed constants, so this carries no
      // visitor input.
      dangerouslySetInnerHTML={{ __html: inner }}
    />
  );
}
