import { VISIBILITY_FLASH_MESSAGES, type VisibilityFlashKey } from "@/lib/games/formState";

function isVisibilityFlashKey(value: string): value is VisibilityFlashKey {
  return Object.hasOwn(VISIBILITY_FLASH_MESSAGES, value);
}

/**
 * A hide or show control's own confirmation, carried past the row or page a
 * successful write removes from the tree (#374). `setGameVisibility` and
 * `setVersionVisibility` (`@/app/games/actions`) redirect here with a fixed
 * key on the `visibility` search param, for the two controls whose own
 * `VisibilityToggleForm` message can never render: the moderation queue's
 * unhide buttons, and a game's own "Hide this game" shortcut. `role="status"`
 * for the same reason `VisibilityToggleForm` uses it for a success - this is
 * always the success half, never the refusal one.
 *
 * The param carries a key rather than the message itself, because a search
 * param is visitor controlled: rendering whatever text arrived on it would
 * let anyone hand a moderator a link that puts words on the hub's own page
 * that were never the hub's. An unknown key - including free text, which
 * matches none of the four - renders nothing.
 */
export function VisibilityFlash({ flashKey }: { flashKey: string | undefined }) {
  if (!flashKey || !isVisibilityFlashKey(flashKey)) return null;
  return (
    <p role="status" className="text-sm text-neutral-300">
      {VISIBILITY_FLASH_MESSAGES[flashKey]}
    </p>
  );
}
