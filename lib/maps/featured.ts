/**
 * What the catalog's feature control says (#394).
 *
 * Its own set rather than a reuse of `GameFormState` (`lib/games/formState.ts`)
 * or `ItemFormState` (`lib/gallery/featured.ts`), for the reason the item one
 * gives for not reusing the game one: the words are specific to what can go
 * wrong featuring a map. The shape is the same, so `VisibilityToggleForm`
 * draws all three.
 */
export interface MapFormState {
  ok: boolean;
  message: string;
}

export const MAP_FEATURED_MESSAGES = {
  featured: "Map featured.",
  unfeatured: "Map no longer featured.",
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "Only a moderator can feature a map.",
  notFound: "No map with that id.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;
