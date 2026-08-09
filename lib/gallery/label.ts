/**
 * What to call a thing on screen.
 *
 * Warpath and conquest are both `kind: challenge` and differ only by mode. To a
 * player they are separate things, so the mode is the label when there is one and
 * the kind is the fallback. Anything unrecognised shows as itself rather than as
 * "Unknown", because a new mode from a newer coilbox is better read raw than
 * hidden.
 */
const KIND: Record<string, string> = {
  preset: "Preset",
  challenge: "Challenge",
  "setup-pack": "Setup pack",
  scenario: "Scenario",
};

const MODE: Record<string, string> = {
  warpath: "Warpath",
  conquest: "Conquest",
};

export function itemLabel(kind: string, mode?: string | null): string {
  if (mode) return MODE[mode] ?? mode;
  return KIND[kind] ?? kind;
}

/** The plural, for a filter chip. */
export function kindLabelPlural(kind: string): string {
  return (
    { preset: "Presets", challenge: "Challenges", "setup-pack": "Setup packs", scenario: "Scenarios" }[
      kind
    ] ?? kind
  );
}
