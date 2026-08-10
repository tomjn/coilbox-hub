/**
 * The palette maths every backdrop drawing in `components/art/drawings.ts`
 * needs, factored out of what was a single copy inside `HubArt.tsx` so it is
 * shared once rather than duplicated per drawing. Ported from Coilbox's
 * `paletteFor` in `src/home/bundledArt.ts`, with the light-scheme ramp
 * dropped: this site sets `color-scheme: dark` and nothing else, so a mark's
 * lightness is always its dark-card value, which is what
 * `schemeLightness(scheme, dark)` returns unchanged for `scheme === "dark"`.
 * `fieldTop`/`fieldFoot`, the panel background wash every bundled card paints
 * behind its pools and subject, are also dropped: nothing here draws a
 * full-canvas field rect (see `components/art/CoilArt.tsx`), so nothing reads
 * them. That wash is authored for a card that needs its own background and is
 * wrong over the page as a backdrop, the same finding that took it out of the
 * `hub` copy this module now shares with the rest.
 */

/** Canvas every drawing is authored against, matching Coilbox's own. */
export const WIDTH = 320;
export const HEIGHT = 200;

/** hsl(0 0% 98.04%): the `--foreground` value in `app/globals.css`, pre-parsed
 * the way Coilbox's `parseColor` would read it. Every drawing on this site
 * tints from this one theme colour, since the site has no accent beyond a
 * near-white foreground on a near-black field. */
export const THEME = { h: 0, s: 0, l: 98.04 };

/** Below this saturation Coilbox treats a theme as achromatic. Same number as
 * `ACHROMATIC_SATURATION` in `bundledArt.ts`. */
const ACHROMATIC_SATURATION = 8;

export function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

export function round(n: number): number {
  return Math.round(n * 100) / 100;
}

export function hsl(h: number, s: number, l: number): string {
  const hue = round(((h % 360) + 360) % 360);
  return `hsl(${hue} ${round(clamp(s, 0, 100))}% ${round(clamp(l, 0, 100))}%)`;
}

/** A diamond as a polygon, matching Coilbox's `diamond`. Shared here because
 * more than one drawing below uses it. */
export function diamond(x: number, y: number, r: number): string {
  return `<polygon points="${x},${y - r} ${x + r},${y} ${x},${y + r} ${x - r},${y}"/>`;
}

export interface Palette {
  glow: string;
  faint: string;
  line: string;
  spark: string;
}

/** Coilbox's `paletteFor`, minus the two fields nothing here reads. See the
 * module comment for why. */
export function paletteFor(theme: typeof THEME): Palette {
  const neutral = theme.s < ACHROMATIC_SATURATION;
  const sat = neutral ? clamp(theme.s, 0, 6) : clamp(theme.s, 24, 58);
  const hue = (offset: number) => (neutral ? theme.h : theme.h + offset);
  return {
    glow: hsl(hue(22), Math.min(sat + 14, 70), 58),
    faint: hsl(hue(0), Math.min(sat + 6, 62), 54),
    line: hsl(hue(6), Math.min(sat + 18, 72), 70),
    spark: hsl(hue(-16), neutral ? sat : Math.min(sat + 30, 82), 80),
  };
}

/** Computed once: every drawing shares the same theme, so there is only ever
 * one palette on this site. */
export const palette = paletteFor(THEME);
