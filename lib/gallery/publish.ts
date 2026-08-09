import {
  asContainer,
  type Container,
  decodeContainerText,
  GALLERY_KINDS,
  type GalleryKind,
  type GameIdentity,
  identify,
  makeContainer,
  MAX_CONTAINER_BYTES,
} from "@/lib/container";

/**
 * Everything between a pasted share code and a row. The rules here are the app's
 * rules, not ours: anything accepted must be something coilbox will import, or
 * the gallery hands out links that fail on arrival.
 */

export interface AcceptedContainer {
  container: Container;
  kind: GalleryKind;
  kindVersion: number;
  gameName: string | null;
  mapName: string | null;
}

export type AcceptResult =
  | { ok: true; accepted: AcceptedContainer }
  | { ok: false; reason: string };

function isGalleryKind(kind: string): kind is GalleryKind {
  return (GALLERY_KINDS as readonly string[]).includes(kind);
}

function str(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

/**
 * Pull out the map name a listing filters on. Each kind names it differently,
 * and only the shapes actually seen are handled. A kind whose payload has not
 * been looked at yields null rather than a guess at a field name that may not
 * exist, because a wrong map name is worse than no map name.
 *
 * The game name is not derived here: `gameIdentityFromPayload` (via
 * `identify()`) already covers every kind, scenario included, from the one
 * `game` field every kind now writes, or its kind's old spelling when it does
 * not. Re-reading a kind's old spelling here would be the same duplication
 * this function used to be.
 *
 * Exported so scripts/backfill-game-names.ts can derive the same gameName for
 * an already-stored row without a second copy of this logic.
 */
export function describe(
  kind: GalleryKind,
  payload: unknown,
  game: GameIdentity | undefined,
) {
  // Shortname is the stable, human-facing identifier (see gameIdentity.ts):
  // unlike the exact pinned build a preset, setup pack or challenge may carry,
  // it does not change every time the game updates, which is what a listing
  // filters and groups by. It falls back to the pinned name when a game has
  // no shortname, so an item still shows something rather than nothing.
  const gameName = game ? (game.shortname ?? game.name ?? null) : null;

  if (typeof payload !== "object" || payload === null) {
    return { gameName, mapName: null };
  }
  const p = payload as Record<string, unknown>;

  if (kind === "preset") {
    return { gameName, mapName: str(p.mapName) };
  }

  if (kind === "setup-pack") {
    const maps = Array.isArray(p.maps) ? p.maps : [];
    return {
      gameName,
      // A pack can carry several maps and the row holds one, so it is only
      // filled in when there is no ambiguity about which it would mean.
      mapName: maps.length === 1 ? str(maps[0]) : null,
    };
  }

  return { gameName, mapName: null };
}

/**
 * Take what people actually have. Coilbox's share affordance produces a
 * `coilbox://import?code=…` link, not a bare code, so asking for a code and
 * refusing a link means refusing the only thing on their clipboard.
 *
 * Raw codes and raw JSON still work, because a saved export is JSON and someone
 * may well paste the contents of one.
 */
function unwrapShareLink(
  trimmed: string,
): { ok: true; code: string } | { ok: false; reason: string } {
  if (trimmed === "") {
    return { ok: false, reason: "Paste a share link or code first." };
  }
  if (!trimmed.toLowerCase().startsWith("coilbox://")) {
    return { ok: true, code: trimmed };
  }

  let link: URL;
  try {
    link = new URL(trimmed);
  } catch {
    return { ok: false, reason: "That coilbox link is malformed." };
  }

  const code = link.searchParams.get("code");
  if (code) return { ok: true, code };

  // The other two link shapes are real links that simply are not a thing to
  // publish, so they get told apart rather than lumped in with junk.
  if (link.searchParams.get("url")) {
    return {
      ok: false,
      reason:
        "That link points at a file hosted somewhere else. Paste the file's contents instead.",
    };
  }
  return {
    ok: false,
    reason: "That is a coilbox link, but it does not carry anything to publish.",
  };
}

/**
 * Decide whether a pasted link, code or uploaded file can be published, and pull
 * out what a listing needs. Never throws: an unusable input comes back with a
 * reason a person can act on rather than a validation code.
 */
export function accept(input: string): AcceptResult {
  const unwrapped = unwrapShareLink(input.trim());
  if (!unwrapped.ok) return unwrapped;
  const trimmed = unwrapped.code;

  const result = identify(trimmed);

  if (result.kind === "unknown") {
    return {
      ok: false,
      reason:
        "That is not something coilbox made. Use Share in the app and paste the link it copies.",
    };
  }

  if (result.compatibility === "newer") {
    return {
      ok: false,
      reason:
        "This came from a newer coilbox than the gallery understands. It cannot be published yet.",
    };
  }

  if (!isGalleryKind(result.kind)) {
    return {
      ok: false,
      reason: `The gallery does not carry ${result.kind}s yet.`,
    };
  }

  const decoded = decodeContainerText(trimmed);
  if (decoded === null) {
    return { ok: false, reason: "That share code could not be read." };
  }

  // identify() also recognises a legacy bare preset, which carries no envelope at
  // all. The gallery stores containers, so one gets wrapped in the envelope it
  // would have today rather than being turned away for its age.
  const container =
    asContainer(decoded) ??
    makeContainer(result.kind, result.version || 1, decoded);

  // The same ceiling the app enforces on import. Publishing anything larger would
  // mean handing out a link coilbox refuses to open.
  const size = new TextEncoder().encode(JSON.stringify(container)).byteLength;
  if (size > MAX_CONTAINER_BYTES) {
    return {
      ok: false,
      reason: "This is too large to share. Coilbox would refuse to import it.",
    };
  }

  return {
    ok: true,
    accepted: {
      container,
      kind: result.kind,
      kindVersion: container.kindVersion,
      ...describe(result.kind, container.payload, result.game),
    },
  };
}
