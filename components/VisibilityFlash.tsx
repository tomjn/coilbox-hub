/**
 * A hide or show control's own confirmation, carried past the row or page a
 * successful write removes from the tree (#374). `setGameVisibility` and
 * `setVersionVisibility` (`@/app/games/actions`) redirect here with the
 * message on the `visibility` search param, for the two controls whose own
 * `VisibilityToggleForm` message can never render: the moderation queue's
 * unhide buttons, and a game's own "Hide this game" shortcut. `role="status"`
 * for the same reason `VisibilityToggleForm` uses it for a success - this is
 * always the success half, never the refusal one.
 */
export function VisibilityFlash({ message }: { message: string | undefined }) {
  if (!message) return null;
  return (
    <p role="status" className="text-sm text-neutral-300">
      {message}
    </p>
  );
}
