/**
 * What the gallery's feature control says (#395).
 *
 * Its own set rather than a reuse of the games one (`lib/games/formState.ts`),
 * because two of the answers are about an item and have no equivalent for a
 * game: an item can be withdrawn out from under the control while a moderator
 * is looking at it, and nobody but a moderator may feature one at all. The
 * shape is the same, so `VisibilityToggleForm` draws both.
 */
export interface ItemFormState {
  ok: boolean;
  message: string;
}

export const ITEM_FEATURED_MESSAGES = {
  featured: "Featured in the gallery.",
  unfeatured: "No longer featured.",
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "Only a moderator can feature a gallery item.",
  /** The database wrote nothing, which for a moderator pressing a button on an
   *  item they are looking at means the item moved underneath them. Featuring
   *  a withdrawn item is refused, since a reader would never see it. */
  nothingChanged:
    "Nothing changed. The item may have been withdrawn or removed since this page loaded.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;
