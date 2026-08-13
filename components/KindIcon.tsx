import { Icon } from "@/components/icons";

/**
 * A glyph per kind of thing, so the grid can be scanned rather than read. The
 * badge text is the label, so nothing here is announced.
 *
 * Warpath and conquest share the dots and lines vocabulary because they are the
 * same kind underneath, and differ by linear against branching, which is the
 * difference the payload actually records.
 */

/** Two teams facing each other, which is what a preset describes. Two blocks
 * rather than a grid of slots, because at 14px the slots fill in solid. */
function Preset() {
  return (
    <>
      <rect x="3" y="5" width="7" height="14" rx="2" />
      <rect x="14" y="5" width="7" height="14" rx="2" />
      <path d="M12 3v18" />
    </>
  );
}

/** A route with one destination. */
function Warpath() {
  return (
    <>
      <path d="M4 17l5-5 5 3 4.5-6" />
      <circle cx="20" cy="7" r="2.5" />
    </>
  );
}

/** Systems joined to each other, with no single end. */
function Conquest() {
  return (
    <>
      <circle cx="6" cy="7" r="2.5" />
      <circle cx="18" cy="8" r="2.5" />
      <circle cx="12" cy="18" r="2.5" />
      <path d="M8.5 7.2l7 0.6" />
      <path d="M7.2 9.2l3.6 6.6" />
      <path d="M16.7 10.1l-3.4 5.8" />
    </>
  );
}

/** A game, an engine and its maps, stacked into one thing to install. */
function SetupPack() {
  return (
    <>
      <path d="M12 3l9 4.5-9 4.5-9-4.5L12 3z" />
      <path d="M3 12.5l9 4.5 9-4.5" />
      <path d="M3 17l9 4.5 9-4.5" />
    </>
  );
}

/** An objective to reach. */
function Scenario() {
  return (
    <>
      <path d="M6 21V3" />
      <path d="M6 3h12l-3 3.5L18 10H6" />
    </>
  );
}

/** Buildings of different sizes on a plot, which is what the preview draws in
 * full. Three rather than four, and none of them the same, so it is not the
 * gallery's own grid of equal squares. */
function Blueprint() {
  return (
    <>
      <rect x="3" y="3" width="9" height="9" rx="1.5" />
      <rect x="15" y="3" width="6" height="6" rx="1.5" />
      <rect x="3" y="15" width="18" height="6" rx="1.5" />
    </>
  );
}

/** Mode before kind, matching `itemLabel`. An unrecognised kind gets no glyph
 * rather than a wrong one, the same way the label falls back to itself. */
export function KindIcon({
  kind,
  mode,
  className,
}: {
  kind: string;
  mode?: string | null;
  className?: string;
}) {
  const glyph =
    mode === "warpath" ? (
      <Warpath />
    ) : mode === "conquest" ? (
      <Conquest />
    ) : kind === "preset" ? (
      <Preset />
    ) : kind === "setup-pack" ? (
      <SetupPack />
    ) : kind === "scenario" ? (
      <Scenario />
    ) : kind === "blueprint" ? (
      <Blueprint />
    ) : null;

  if (!glyph) return null;
  return <Icon className={className}>{glyph}</Icon>;
}
