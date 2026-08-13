/**
 * What to call a thing on screen.
 *
 * Warpath and conquest are both `kind: challenge` and differ only by mode. To a
 * player they are separate things, so the mode is the label when there is one and
 * the kind is the fallback. Anything unrecognised shows as itself rather than as
 * "Unknown", because a new mode from a newer coilbox is better read raw than
 * hidden.
 */
import { GALLERY_KINDS } from "@/lib/container";

const KIND: Record<string, string> = {
  preset: "Preset",
  challenge: "Challenge",
  "setup-pack": "Setup pack",
  scenario: "Scenario",
  blueprint: "Blueprint",
};

const MODE: Record<string, string> = {
  warpath: "Warpath",
  conquest: "Conquest",
};

export function itemLabel(kind: string, mode?: string | null): string {
  if (mode) return MODE[mode] ?? mode;
  return KIND[kind] ?? kind;
}

const KIND_PLURAL: Record<string, string> = {
  preset: "Presets",
  challenge: "Challenges",
  "setup-pack": "Setup packs",
  scenario: "Scenarios",
  blueprint: "Blueprints",
};

/** The plural, for a filter chip. */
export function kindLabelPlural(kind: string): string {
  return KIND_PLURAL[kind] ?? kind;
}

/** "a, b and c", or "a, b or c". */
function joined(words: string[], conjunction: string): string {
  const last = words.pop() ?? "";
  return words.length > 0
    ? `${words.join(", ")} ${conjunction} ${last}`
    : last;
}

/** A label mid-sentence. Every label here is written to start one. */
const lower = (label: string) => label.charAt(0).toLowerCase() + label.slice(1);

/**
 * What the gallery carries, to open a sentence with: "Presets, challenges,
 * setup packs, scenarios and blueprints".
 *
 * Built from {@link GALLERY_KINDS} rather than written out
 * (tomjn/coilbox#1502). The hand written version of this sentence sat directly
 * above a row of filter chips that had grown a fifth kind, telling every
 * visitor the site carried four.
 */
export function kindsPlural(): string {
  const [first, ...rest] = GALLERY_KINDS.map(kindLabelPlural);
  return joined([first, ...rest.map(lower)], "and");
}

/** The same list part way through a sentence, after "the" or "share the". */
export function kindsPluralLower(): string {
  return lower(kindsPlural());
}

/** The same kinds in the singular, to follow "a" with: "preset, challenge,
 *  setup pack, scenario or blueprint". */
export function kindsSingular(): string {
  return joined(
    GALLERY_KINDS.map((kind) => lower(itemLabel(kind))),
    "or",
  );
}
