/**
 * Pure logic behind the preset preview in `components/ItemPreview.tsx`, split
 * out so it can be unit tested without a rendering library. See that file for
 * why this shows composition (teams, participants, colours, sides) rather
 * than a map diagram: the payload carries no start positions to draw.
 */

export interface PresetParticipant {
  kind?: "you" | "ai";
  name?: string;
  ai?: { shortName?: string; name?: string };
  side?: string;
  /** `Rgb` in coilbox's own `src/play/participants.ts`: a 3-tuple, not an
   * object. Publishing real presets and reading them back is what caught this
   * the first time round - the object shape read as black for every row. */
  color?: [number, number, number];
  allyTeam?: number;
  spectator?: boolean;
}

export interface PresetTeam {
  allyTeam: number;
  members: PresetParticipant[];
}

export interface PresetComposition {
  teams: PresetTeam[];
  playingCount: number;
}

/** Play-side colours are floats from 0 to 1, not bytes. Reading them as bytes
 * produces black for everything, which is a mistake this codebase has made
 * before in the other direction. Out-of-range or missing components clamp to
 * the nearest valid byte rather than producing `NaN` in a CSS string. */
export function participantColorCss(color: PresetParticipant["color"]): string {
  const to = (v: number | undefined) =>
    Math.round(Math.min(1, Math.max(0, v ?? 0)) * 255);
  const [r, g, b] = color ?? [];
  return `rgb(${to(r)} ${to(g)} ${to(b)})`;
}

export function participantLabel(p: PresetParticipant): string {
  if (p.kind === "you") return p.name || "You";
  return p.ai?.name || p.ai?.shortName || p.name || "Open slot";
}

/** Sentinel coilbox writes for "roll a concrete side at launch"
 * (`RANDOM_SIDE` in `src/play/participants.ts`). Not part of the vendored
 * container format, so it is not exported anywhere the hub can import it from
 * - mirrored here rather than left to leak as raw text, which is what a real
 * published preset actually showed before this was read. */
const RANDOM_SIDE = "__random__";

export function participantSideLabel(side: string | undefined): string | null {
  if (!side) return null;
  if (side === RANDOM_SIDE) return "Random";
  return side;
}

/**
 * Group a preset payload's participants into ally teams, ordered by ally team
 * number, dropping spectators (only meaningful on the "you" row - see
 * `Participant` in coilbox). `null` when nobody is actually playing, so the
 * caller can render nothing rather than an empty team list: a spectator-only
 * preset has no composition to show.
 */
export function presetComposition(
  payload: Record<string, unknown>,
): PresetComposition | null {
  const participants = (
    Array.isArray(payload.participants) ? payload.participants : []
  ) as PresetParticipant[];
  const playing = participants.filter((p) => !p.spectator);
  if (playing.length === 0) return null;

  const byTeam = new Map<number, PresetParticipant[]>();
  for (const p of playing) {
    const key = p.allyTeam ?? 0;
    byTeam.set(key, [...(byTeam.get(key) ?? []), p]);
  }
  const teams = [...byTeam.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([allyTeam, members]) => ({ allyTeam, members }));

  return { teams, playingCount: playing.length };
}

/** How the engine will choose start positions, in the same words coilbox's own
 * setup screen uses (`START_POS_OPTIONS` in its `GameOptionsPanel`). */
function startPosLabel(startPosType: unknown): string | null {
  if (startPosType === 0) return "Fixed map start positions";
  if (startPosType === 1) return "Random start positions";
  if (startPosType === 2) return "Players choose in game";
  return null;
}

/**
 * What the minimap's caption says about start positions, or null when the item
 * does not say.
 *
 * Only a preset has this. A setup pack names a map and nothing about how it
 * gets played.
 */
export function startPosNote(kind: string, container: unknown): string | null {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (kind !== "preset" || typeof payload !== "object" || payload === null) {
    return null;
  }
  return startPosLabel((payload as Record<string, unknown>).startPosType);
}
