/**
 * The one action every item has. Coilbox handles the `coilbox://` scheme, so this
 * is an ordinary link: the browser hands it to the app and the app asks the user
 * to confirm before anything is applied.
 */
export function ImportLink({
  shareUrl,
  variant = "quiet",
}: {
  shareUrl: string;
  variant?: "quiet" | "solid";
}) {
  const className =
    variant === "solid"
      ? "inline-flex items-center gap-2 self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white"
      : "inline-flex items-center gap-1.5 self-start text-sm text-neutral-300 underline-offset-4 hover:underline";

  return (
    <a
      href={`coilbox://import?url=${encodeURIComponent(shareUrl)}`}
      className={className}
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className={variant === "solid" ? "size-4" : "size-3.5"}
      >
        <path d="M12 5v14M5 12h14" />
      </svg>
      Import into Coilbox
    </a>
  );
}
