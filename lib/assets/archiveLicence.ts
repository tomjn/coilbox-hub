/**
 * Opening a map archive far enough to read what it says about its own licence
 * (issue #125).
 *
 * The io half of `./licenceStatement`, which holds the reading comprehension and
 * has no idea what an archive is. This half knows the three container formats
 * and nothing about licences.
 *
 * ## Where this runs
 *
 * On a machine that holds the archives, called from a local maintainer script.
 * Never in a route, never in a function, never in a browser. That is why it is
 * allowed to spawn a process and read the filesystem, and why importing it from
 * anything the app serves is wrong.
 *
 * ## The three formats
 *
 * - `.sdz` is a zip. `fflate` is already a dependency and reads it in process,
 *   and its filter callback means only the candidate files are ever
 *   decompressed, however large the archive is.
 * - `.sd7` is 7-zip, and is the main case rather than the minority one. The
 *   maintainer's `~/.spring/maps` holds 91 of them against 4 `.sdz`, so a zip
 *   only reader would answer for four maps in ninety five. Nothing in this
 *   project reads 7-zip and no npm package was added for it, because the machine
 *   already has a reader: see {@link SEVEN_ZIP_READER}.
 * - `.sdd` is not an archive at all, it is an unpacked directory the engine
 *   loads the same way. Reading one is a `readdir`, so it is handled here rather
 *   than left as a gap.
 *
 * ## Reading a 7-zip archive without a new dependency
 *
 * `bsdtar` ships with macOS at `/usr/bin/bsdtar` and reads 7-zip through
 * libarchive's liblzma. Listing one is a header read and costs about 6 ms even
 * on the largest archive in the collection, and pulling one root file out of a
 * 20 MB archive costs about 27 ms, so the two pass approach below is cheaper
 * than unpacking anything.
 *
 * The alternative, `7zz` from Homebrew, was checked and works for both the list
 * and the extract. It is not coded for. One reader is enough on a machine that
 * has both, and a second spawn grammar is a second thing to get wrong.
 */

import { execFile } from "node:child_process";
import { readdir, readFile } from "node:fs/promises";
import { join } from "node:path";
import { promisify } from "node:util";
import { unzipSync } from "fflate";
import {
  classifyLicenceStatement,
  isLicenceCandidatePath,
  licenceCandidatePaths,
  LICENCE_CANDIDATE_BYTES,
  type ArchiveTextFile,
  type LicenceStatement,
} from "./licenceStatement";

const run = promisify(execFile);

/** The binary this shells out to for `.sd7`. Named in the error when it is absent. */
export const SEVEN_ZIP_READER = "bsdtar";

/**
 * Ceiling on one `bsdtar` invocation's output. A listing of the largest archive
 * in the collection is under 400 entries and a root readme is a few kilobytes,
 * so this is a runaway guard rather than a limit anything real approaches.
 */
const MAX_READER_OUTPUT = 32 * 1024 * 1024;

/**
 * Largest candidate to decompress at all. Above this the file is not a readme,
 * and a `.sdz` entry claiming to be a 2 GB `readme.txt` should not be inflated
 * to find that out. Skipping one is reported as a truncated read, so it comes
 * out as ambiguous rather than as nothing found.
 */
const MAX_CANDIDATE_SOURCE_BYTES = 8 * 1024 * 1024;

export type MapArchiveKind = "sd7" | "sdz" | "sdd";

/** What one archive turned out to say, or why nobody could tell. */
export interface ArchiveLicence {
  /** The path as it was given. */
  archive: string;
  /** Null when the path is not one of the three formats. */
  kind: MapArchiveKind | null;
  /** Null exactly when `error` is set. */
  statement: LicenceStatement | null;
  /** Why the archive could not be read. Null on every successful read. */
  error: string | null;
}

/**
 * Names `bsdtar` would treat as a glob rather than as a filename.
 *
 * A candidate carrying one of these is dropped instead of extracted, because
 * `bsdtar` matches its operands as patterns and a pattern could pull out a
 * different entry. Reading the wrong file into a licence claim is the failure
 * this whole module is built to avoid, and a dropped candidate only means the
 * map keeps the blanket default, which is the safe direction.
 */
const GLOB_CHARACTERS = /[*?[\]]/;

function kindOf(archivePath: string): MapArchiveKind | null {
  const lower = archivePath.toLowerCase();
  if (lower.endsWith(".sd7")) return "sd7";
  if (lower.endsWith(".sdz")) return "sdz";
  if (lower.endsWith(".sdd")) return "sdd";
  return null;
}

/**
 * Bytes as text, or null when they are not text.
 *
 * A NUL byte means the entry is a picture or a model that happens to be called
 * `readme.txt`, and running a licence pattern over decoded binary is a way to
 * match wording nobody wrote.
 */
function asText(bytes: Uint8Array, path: string): ArchiveTextFile | null {
  const capped = bytes.subarray(0, LICENCE_CANDIDATE_BYTES);
  if (capped.includes(0)) return null;
  return {
    path,
    text: new TextDecoder("utf-8").decode(capped),
    truncated: bytes.length > LICENCE_CANDIDATE_BYTES,
  };
}

/** A candidate that exists but could not be read. Ambiguous, never nothing. */
function unreadable(path: string): ArchiveTextFile {
  return { path, text: "", truncated: true };
}

/**
 * Whether the `.sd7` reader is on this machine.
 *
 * Exported so a seed run can ask once, before walking three thousand archives,
 * rather than collecting three thousand identical failures.
 */
export async function sevenZipReaderAvailable(): Promise<boolean> {
  try {
    await run(SEVEN_ZIP_READER, ["--version"]);
    return true;
  } catch {
    return false;
  }
}

/** A zip, read in process. Only the candidates are ever decompressed. */
async function readZip(archivePath: string): Promise<ArchiveTextFile[]> {
  const bytes = await readFile(archivePath);
  const oversized: string[] = [];

  const unpacked = unzipSync(bytes, {
    filter: (file) => {
      if (!isLicenceCandidatePath(file.name)) return false;
      // 0 is stored and 8 is deflate. fflate throws on anything else, and a
      // licence file compressed with LZMA inside a zip is not worth a second
      // code path.
      const readable = file.compression === 0 || file.compression === 8;
      if (!readable || file.originalSize > MAX_CANDIDATE_SOURCE_BYTES) {
        oversized.push(file.name);
        return false;
      }
      return true;
    },
  });

  const read = Object.entries(unpacked)
    .map(([path, content]) => asText(content, path))
    .filter((file): file is ArchiveTextFile => file !== null);

  return [...read, ...oversized.map(unreadable)];
}

/** A 7-zip archive, read through {@link SEVEN_ZIP_READER}. */
async function readSevenZip(archivePath: string): Promise<ArchiveTextFile[]> {
  const listing = await run(SEVEN_ZIP_READER, ["-tf", archivePath], {
    maxBuffer: MAX_READER_OUTPUT,
  });

  const candidates = licenceCandidatePaths(listing.stdout.split("\n")).filter(
    (path) => !GLOB_CHARACTERS.test(path),
  );

  const files: ArchiveTextFile[] = [];
  for (const path of candidates) {
    const { stdout } = await run(SEVEN_ZIP_READER, ["-xOf", archivePath, "--", path], {
      encoding: "buffer",
      maxBuffer: MAX_READER_OUTPUT,
    });
    files.push(asText(stdout, path) ?? unreadable(path));
  }

  return files;
}

/** An unpacked directory, which the engine loads the same way as an archive. */
async function readDirectory(archivePath: string): Promise<ArchiveTextFile[]> {
  const entries = await readdir(archivePath, { withFileTypes: true });
  const candidates = entries
    .filter((entry) => entry.isFile() && isLicenceCandidatePath(entry.name))
    .map((entry) => entry.name);

  const files: ArchiveTextFile[] = [];
  for (const path of candidates) {
    const bytes = await readFile(join(archivePath, path));
    files.push(asText(bytes, path) ?? unreadable(path));
  }

  return files;
}

/**
 * What one map archive says about its own licence.
 *
 * Never throws for a bad archive. A file that is missing, corrupt, or in a
 * format nobody here reads comes back with `error` set and no statement, because
 * a seed walking three thousand archives should log the awkward ones and carry
 * on rather than stop on the first one somebody truncated mid download.
 *
 * A successful read almost always answers `none`, and `none` is not a refusal.
 * It means the archive said nothing about terms, so the blanket map row keeps
 * answering for that map exactly as it did before.
 *
 * Pair the result with the map's canonical name through
 * `proposeMapLicence` in `./licenceStatement`. This function is deliberately not
 * told the map name: it is given a path, and the path is not the canonical name.
 */
export async function readArchiveLicence(archivePath: string): Promise<ArchiveLicence> {
  const kind = kindOf(archivePath);
  if (!kind) {
    return {
      archive: archivePath,
      kind: null,
      statement: null,
      error: "Not a .sd7, .sdz or .sdd",
    };
  }

  if (kind === "sd7" && !(await sevenZipReaderAvailable())) {
    return {
      archive: archivePath,
      kind,
      statement: null,
      error: `Reading a .sd7 needs ${SEVEN_ZIP_READER} on PATH, and it is not there`,
    };
  }

  try {
    const files =
      kind === "sdz"
        ? await readZip(archivePath)
        : kind === "sd7"
          ? await readSevenZip(archivePath)
          : await readDirectory(archivePath);

    return {
      archive: archivePath,
      kind,
      statement: classifyLicenceStatement(files),
      error: null,
    };
  } catch (cause) {
    return {
      archive: archivePath,
      kind,
      statement: null,
      error: cause instanceof Error ? cause.message : String(cause),
    };
  }
}
