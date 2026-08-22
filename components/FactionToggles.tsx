import Link from "next/link";

/**
 * Factions as a row of toggles rather than a dropdown (#269).
 *
 * A side is a top level choice about what you are looking at, not a field
 * beside the search box, so each option is its own link: the URL carries the
 * choice, scripting off works, and the back button walks back through sides.
 * The active side reads as pressed, in the same words the release picker uses.
 */

export interface FactionToggleOption {
  /** Stable identity for keys and links; "" selects every faction. */
  key: string;
  label: string;
  href: string;
  active: boolean;
}

export function FactionToggles({
  options,
  label = "Faction",
}: {
  options: FactionToggleOption[];
  label?: string;
}) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap items-center gap-1.5">
      {options.map((option) =>
        option.active ? (
          <span
            key={option.key || "all"}
            aria-current="true"
            className="rounded-md border border-neutral-600 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-100"
          >
            {option.label}
          </span>
        ) : (
          <Link
            key={option.key || "all"}
            href={option.href}
            className="rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            {option.label}
          </Link>
        ),
      )}
    </div>
  );
}
