/**
 * Where coilbox fetches a game from, and whether what somebody typed is a thing
 * of that kind.
 *
 * Three kinds, and the set is closed, which is why the kind is a column with a
 * check constraint on it rather than another entry in the `links` blob. The
 * shapes are the ones coilbox's own `catalog.json` already carries: a rapid tag
 * as `{"kind": "rapid", "tag": "metalfactions:stable"}`, and a repo as the
 * `owner/repo` its `githubGameRepos` entries hold.
 *
 * A game holds an ordered list of these rather than one (#396). The order is
 * the order to try: coilbox's `downloadGame.ts` walks a source list until one
 * yields the file, and the sources really are not interchangeable.
 * `Balanced-Annihilation/Balanced-Annihilation` publishes no releases at all,
 * so only its rapid tag works, while SplinterFaction ships only through GitHub
 * releases.
 *
 * ## The two optional details
 *
 * Both are arguments coilbox's downloader already takes, so a source stored
 * here is one its existing code can run unchanged.
 *
 * `asset` belongs to a github source. The client asks GitHub for the last 30
 * releases, keeps the `.sd7` and `.sdz` assets, and picks the one whose
 * filename contains `asset`, or the newest when there is none. It is a
 * fragment rather than a whole filename because a whole filename carries the
 * version and would want editing on every release.
 *
 * `filename` belongs to a url source, and is what to save the download as. The
 * client needs one and cannot work it out, since a URL is not obliged to end
 * in a filename.
 *
 * Neither a checksum nor a version is here, because coilbox verifies no hash
 * and compares no version. See the migration for the whole argument.
 *
 * The format lives here rather than in the check constraints because these are
 * strings a person types into a form, and a constraint violation is not a
 * sentence anybody can act on. The constraints still hold the kind, the
 * lengths and which detail belongs to which kind, so a write that came in some
 * other way cannot store nonsense.
 */

export const DOWNLOAD_KINDS = ["rapid", "url", "github"] as const;

export type DownloadKind = (typeof DOWNLOAD_KINDS)[number];

export interface GameDownload {
  kind: DownloadKind;
  value: string;
  /** github only: part of the release archive's filename. */
  asset?: string;
  /** url only: what to save the file as. */
  filename?: string;
}

export type ParsedDownload =
  | { ok: true; download: GameDownload | null }
  | { ok: false; message: string };

export type ParsedDownloads =
  | { ok: true; downloads: GameDownload[] }
  | { ok: false; message: string };

/** What the value column holds. Longer than any real tag, address or repo
 *  path, and short enough that a paste of something else is refused here
 *  rather than by the database. */
const MAX_VALUE = 512;

/** What the two detail columns hold, which is a filename or a piece of one. */
const MAX_DETAIL = 256;

/** The same bound the conquest faction list puts on itself. A game with more
 *  than a dozen places to get it from has a different problem. */
export const MAX_DOWNLOADS = 12;

/** One colon between two non-empty halves, which is how every tag in coilbox's
 *  catalog reads. */
const RAPID = /^[^\s:]+:[^\s:]+$/;

/** GitHub's own rule for both halves: letters, digits, dot, dash and
 *  underscore, with exactly one slash between them. */
const REPO = /^[\w.-]+\/[\w.-]+$/;

/** A filename and nothing around it. A path separator here would let a saved
 *  download land somewhere the client did not mean to write. */
const FILENAME = /^[^\s/\\]+$/;

function refuse(message: string): ParsedDownload {
  return { ok: false, message };
}

/**
 * Read one source: the kind, the value, and whichever detail the kind takes.
 *
 * A blank value clears the source whatever the kind says, because a select
 * sitting on its default beside an empty box is somebody who did not fill this
 * in rather than somebody who got it wrong.
 *
 * A detail belonging to another kind is dropped rather than refused. The edit
 * form keeps a row's boxes as the person switches the kind, so leftover text
 * from a kind they moved away from is a stale box and not a mistake.
 */
export function parseDownload(
  kind: string,
  value: string,
  asset = "",
  filename = "",
): ParsedDownload {
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

    const saveAs = filename.trim();
    if (saveAs === "") {
      return refuse('An address needs the filename to save it as, like "metal_factions.sdz".');
    }
    if (saveAs.length > MAX_DETAIL || !FILENAME.test(saveAs)) {
      return refuse('A filename is one name with no slashes or spaces, like "metal_factions.sdz".');
    }
    return { ok: true, download: { kind, value: trimmed, filename: saveAs } };
  }

  if (kind === "github") {
    if (!REPO.test(trimmed)) {
      return refuse('A GitHub repo looks like "owner/repo", not a full address.');
    }

    const part = asset.trim();
    if (part.length > MAX_DETAIL) {
      return refuse(`A release file is at most ${MAX_DETAIL} characters. That one is ${part.length}.`);
    }
    // Optional. With nothing here coilbox takes the newest archive the repo
    // has released, which is right for a repo that ships one game.
    return { ok: true, download: part === "" ? { kind, value: trimmed } : { kind, value: trimmed, asset: part } };
  }

  return refuse("Pick how the game is downloaded.");
}

/** One row as the edit form sends it, before anything has been checked. */
interface DownloadRow {
  kind?: unknown;
  value?: unknown;
  asset?: unknown;
  filename?: unknown;
}

function text(value: unknown): string {
  return typeof value === "string" ? value : "";
}

/**
 * The whole list as the edit form sends it: one JSON array, in the order to
 * try.
 *
 * Empty rows drop out, the way a blank value clears a single source, so a
 * person who added a row and changed their mind is not stopped from saving.
 * Anything else that is wrong refuses the save and says which row, because a
 * quietly discarded source reads on the page as a source that was never typed.
 */
export function parseDownloads(payload: string): ParsedDownloads {
  let rows: unknown;
  try {
    rows = JSON.parse(payload || "[]");
  } catch {
    return { ok: false, message: "The download list did not arrive. Try saving again." };
  }
  if (!Array.isArray(rows)) {
    return { ok: false, message: "The download list did not arrive. Try saving again." };
  }
  if (rows.length > MAX_DOWNLOADS) {
    return { ok: false, message: `A game holds at most ${MAX_DOWNLOADS} download sources.` };
  }

  const downloads: GameDownload[] = [];
  for (const [index, row] of rows.entries()) {
    if (typeof row !== "object" || row === null) continue;
    const { kind, value, asset, filename } = row as DownloadRow;
    const parsed = parseDownload(text(kind), text(value), text(asset), text(filename));
    if (!parsed.ok) return { ok: false, message: `Source ${index + 1}: ${parsed.message}` };
    if (parsed.download) downloads.push(parsed.download);
  }
  return { ok: true, downloads };
}

/**
 * The list a read handed back, with anything it cannot understand left out.
 *
 * Defensive in the way `parseGameLinks` is: the value arrives as jsonb through
 * a view, so a row written by something other than the edit form is possible
 * and must not be able to break a page.
 *
 * Deliberately not `parseDownload`. A writer's rules and a reader's rules are
 * different jobs, and running the writer's over stored rows would make a row
 * the form would refuse today vanish off the page rather than show up as
 * something to fix. A url source carried over from the single column has no
 * filename, and it is still the address its owner recorded.
 */
export function readDownloads(value: unknown): GameDownload[] {
  if (!Array.isArray(value)) return [];
  const downloads: GameDownload[] = [];
  for (const row of value) {
    if (typeof row !== "object" || row === null) continue;
    const { kind, value: held, asset, filename } = row as DownloadRow;
    const trimmed = text(held).trim();
    if (trimmed === "") continue;
    if (!(DOWNLOAD_KINDS as readonly string[]).includes(text(kind))) continue;

    const download: GameDownload = { kind: text(kind) as DownloadKind, value: trimmed };
    if (text(asset).trim() !== "") download.asset = text(asset).trim();
    if (text(filename).trim() !== "") download.filename = text(filename).trim();
    downloads.push(download);
  }
  return downloads;
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
