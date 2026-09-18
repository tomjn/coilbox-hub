/**
 * The answer a game edit form shows: saved, or what went wrong and what to do
 * (#362). Before this, `editGameDetails`, `setSnippet`, `setGameVisibility`
 * and `setVersionVisibility` returned nothing either way, so a refused or
 * failed save looked the same as a successful one: the form just sat there.
 *
 * Same shape as `GameImageUploadState` (`./imageUpload`, #354), because the
 * edit page should read the same everywhere. Kept as its own type rather than
 * imported from there, since these forms are not about images.
 */
export interface GameFormState {
  ok: boolean;
  message: string;
}

/** What the words form (display name, description, links) says. */
export const EDIT_MESSAGES = {
  saved: "Details saved.",
  signedOut: "You are signed out. Sign in, then save your changes again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can save these details.",
  notSaved: "The details could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/** What the conquest factions form says (#393). */
export const CONQUEST_FACTIONS_MESSAGES = {
  saved: "Factions saved.",
  signedOut: "You are signed out. Sign in, then save your changes again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can save its factions.",
  notSaved: "The factions could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/** What a unit's author snippet form says. */
export const SNIPPET_MESSAGES = {
  saved: "Snippet saved.",
  signedOut: "You are signed out. Sign in, then save the snippet again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can save its snippets.",
  notSaved: "The snippet could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/** What a hide or show control says, for a game or one of its releases. The
 *  confirmation itself ("Game hidden.", "Release shown again.") is built
 *  where the action already knows which one just changed and which way. */
export const VISIBILITY_MESSAGES = {
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "You can no longer change this. Only the game's owner or a moderator can hide or show it.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/** What the feature control says. Its own set rather than a reuse of
 *  VISIBILITY_MESSAGES, because the refusal is a different one: an owner may
 *  hide their own game and may not feature it, so "the owner or a moderator"
 *  would be wrong here. */
export const FEATURED_MESSAGES = {
  featured: "Game featured.",
  unfeatured: "Game no longer featured.",
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "Only a moderator can feature a game.",
  notFound: "No game with that shortname.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/**
 * The four confirmations a hide or show control's redirect can carry (#374).
 * `setGameVisibility` and `setVersionVisibility` send the visitor on to a
 * page that survives their own write, with one of these keys on the
 * `visibility` search param rather than the message text itself - a URL
 * param is visitor-controlled, and rendering whatever text arrived on it
 * would let anyone hand a moderator a link that puts words on the hub's own
 * page that were never the hub's (`VisibilityFlash`, `components/`). Every
 * message here is one `VisibilityToggleForm` already shows inline, on a
 * control that never redirects, so nothing here is new: the key only
 * survives past the redirect the message text could not.
 */
export const VISIBILITY_FLASH_MESSAGES = {
  "game-hidden": "Game hidden.",
  "game-shown": "Game shown again.",
  "release-hidden": "Release hidden.",
  "release-shown": "Release shown again.",
} as const;

export type VisibilityFlashKey = keyof typeof VISIBILITY_FLASH_MESSAGES;
