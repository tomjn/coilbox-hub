/**
 * The kinds of picture a game carries and the most one may weigh, from the Next
 * app's `lib/api/gameBranding.ts`. The server holds the same rules in
 * `App\Domain\Games\GameArt::KINDS` and `GameBranding::MAX_BYTES`.
 */
export const GAME_IMAGE_KINDS = ["logo", "banner", "card"] as const;

export type GameImageKind = (typeof GAME_IMAGE_KINDS)[number];

export function isGameImageKind(value: string): value is GameImageKind {
  return (GAME_IMAGE_KINDS as readonly string[]).includes(value);
}

export const GAME_BRANDING_MAX_BYTES = 512 * 1024;
