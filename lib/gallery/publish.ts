import {
  asContainer,
  type Container,
  decodeContainerText,
  GALLERY_KINDS,
  type GalleryKind,
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

/** Pull out the two fields a listing filters on. A preset names both, and the
 * other kinds are filled in as each one's preview lands rather than guessed at
 * from field names that may not exist. */
function describe(kind: GalleryKind, payload: unknown) {
  const blank = { gameName: null, mapName: null };
  if (kind !== "preset") return blank;
  if (typeof payload !== "object" || payload === null) return blank;

  const p = payload as Record<string, unknown>;
  return {
    gameName: typeof p.gameName === "string" ? p.gameName : null,
    mapName: typeof p.mapName === "string" ? p.mapName : null,
  };
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
      ...describe(result.kind, container.payload),
    },
  };
}
