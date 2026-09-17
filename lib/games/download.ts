/**
 * Where a lobby fetches a game from, and whether what somebody typed is a thing
 * of that kind.
 *
 * Three kinds, and the set is closed, which is why the kind is a column with a
 * check constraint on it rather than another entry in the `links` blob. The
 * shapes are the ones coilbox's own `catalog.json` already carries: a rapid tag
 * as `{"kind": "rapid", "tag": "metalfactions:stable"}`, and a repo as the
 * `owner/repo` its `githubGameRepos` entries hold.
 *
 * The format lives here rather than in the check constraint because these are
 * strings a person types into a form, and a constraint violation is not a
 * sentence anybody can act on. The constraint still holds the kind and the
 * length, so a write that came in some other way cannot store nonsense.
 */

export const DOWNLOAD_KINDS = ["rapid", "url", "github"] as const;

export type DownloadKind = (typeof DOWNLOAD_KINDS)[number];

export interface GameDownload {
  kind: DownloadKind;
  value: string;
}

export type ParsedDownload =
  | { ok: true; download: GameDownload | null }
  | { ok: false; message: string };

/** What the column holds. Longer than any real tag, address or repo path, and
 *  short enough that a paste of something else is refused here rather than by
 *  the database. */
const MAX_VALUE = 512;

/** One colon between two non-empty halves, which is how every tag in coilbox's
 *  catalog reads. */
const RAPID = /^[^\s:]+:[^\s:]+$/;

/** GitHub's own rule for both halves: letters, digits, dot, dash and
 *  underscore, with exactly one slash between them. */
const REPO = /^[\w.-]+\/[\w.-]+$/;

function refuse(message: string): ParsedDownload {
  return { ok: false, message };
}

/**
 * Read the kind and value a form posted.
 *
 * A blank value clears the download whatever the kind says, because a select
 * sitting on its default beside an empty box is somebody who did not fill this
 * in rather than somebody who got it wrong.
 */
export function parseDownload(kind: string, value: string): ParsedDownload {
  const trimmed = value.trim();
  if (trimmed === "") return { ok: true, download: null };

  if (trimmed.length > MAX_VALUE) {
    return refuse(`A download is at most ${MAX_VALUE} characters. That one is ${trimmed.length}.`);
  }

  if (kind === "rapid") {
    if (!RAPID.test(trimmed)) {
      return refuse('A rapid tag looks like "metalfactions:stable", a name then a colon then a branch.');
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  if (kind === "url") {
    let parsed: URL;
    try {
      parsed = new URL(trimmed);
    } catch {
      return refuse('That is not a web address. It should start with "https://".');
    }
    // Anything else, javascript: above all, would become an href on a page the
    // hub serves to everybody.
    if (parsed.protocol !== "https:" && parsed.protocol !== "http:") {
      return refuse("A download address has to be http or https.");
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  if (kind === "github") {
    if (!REPO.test(trimmed)) {
      return refuse('A GitHub repo looks like "owner/repo", not a full address.');
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  return refuse("Pick how the game is downloaded.");
}

/**
 * Where the download control points, or null when there is nowhere to point.
 *
 * A rapid tag has no address. It is a string a lobby hands to its own
 * downloader, and dressing it as a link would tell a reader it does something
 * it does not.
 */
export function downloadHref(download: GameDownload): string | null {
  if (download.kind === "url") return download.value;
  if (download.kind === "github") return `https://github.com/${download.value}/releases`;
  return null;
}
