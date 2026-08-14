/**
 * What a map archive says about its own licence, read out of the text files it
 * ships (issue #125).
 *
 * Reading comprehension only. Nothing here opens an archive: `./archiveLicence`
 * does that and hands the text over, so the part that has to be careful is a
 * pure function anyone can test without a 130 MB fixture.
 *
 * ## Why this is not a gate
 *
 * `asset_licence` already holds a blanket row permitting every map, on the
 * maintainer's decision of 2026-08-14, and a per map row overrides it (see
 * `./licence` and `supabase/migrations/20260814170100_asset_licence_all_maps.sql`).
 * So finding nothing changes nothing, and finding nothing is the normal result.
 * The point of this module is the rare map that does state terms: recording what
 * that map said, rather than leaving it resting on a default that happens to
 * agree with it.
 *
 * ## What it looks at, and why so little
 *
 * The archive root, and nothing else. That rule comes from the corpus rather
 * than from taste. Across the 95 map archives in the maintainer's
 * `~/.spring/maps`, 16 carry a file whose name contains "licen", "copying" or
 * "readme", and every one of them belongs to something the map bundled rather
 * than to the map:
 *
 *   libs/lcs/zlib LICENSE.txt                a Lua serialisation library
 *   libs/s11n/LICENSE                        another one
 *   objects3d/LowpolyPineTrees/License.txt   a model pack
 *   unittextures/LICENSE.txt                 a texture pack
 *   features/LICENSE.txt                     a feature pack
 *
 * Matching those would write "Zlib" into the licence column of fifteen maps,
 * which is a false claim in the one table whose whole purpose is being able to
 * defend a claim later. One archive out of 95 has a root readme at all, and it
 * reads "damn somebody read this file / they are probably going to jack my palm
 * trees 8)", which is correctly no statement.
 *
 * `mapinfo.lua` is deliberately not read. It sits at the root of 84 of those 95
 * archives, so it is the obvious place to look, and not one of them mentions a
 * licence, a copyright or Creative Commons. That matches what 20260814170100
 * found in BAR's maps-metadata repository, and it is why there is no Lua parser
 * here.
 *
 * ## What it does when it is not sure
 *
 * Says so. The set of licences {@link classifyLicenceStatement} will name is
 * small on purpose, and everything outside it comes back `ambiguous` with the
 * wording that triggered it attached. A person reading eight quoted lines is
 * cheap. A wrong SPDX identifier in `asset_licence` is not, because that row is
 * what a takedown request gets answered from.
 *
 * A statement is never made out of an absence. No candidate file, or a candidate
 * that says nothing about terms, is `none`, and `none` means the blanket default
 * keeps answering for that map.
 */

import type { AssetRedistribution } from "./licence";

/**
 * How much of one candidate file to classify.
 *
 * A licence statement in a map readme is a paragraph. Past a quarter of a
 * megabyte the file is a document rather than a readme, and its tail is not
 * where the terms are. A file cut off here is reported as ambiguous rather than
 * as nothing found, so truncation can never quietly become a "no statement"
 * result.
 */
export const LICENCE_CANDIDATE_BYTES = 256 * 1024;

/** The value to write into `asset_licence.checked_by` for a row this produced. */
export const LICENCE_CHECKED_BY = "map archive reader, issue #125";

/** One text file read out of an archive, ready to be classified. */
export interface ArchiveTextFile {
  /** Archive relative path, forward slashes, as the archive spells it. */
  path: string;
  /** The decoded text, cut at {@link LICENCE_CANDIDATE_BYTES}. */
  text: string;
  /** True when the file was longer than the cap, or too large to read at all. */
  truncated: boolean;
}

/**
 * What the archive turned out to say.
 *
 * - `none` - nothing in it addresses terms. The blanket map row keeps
 *   answering, and there is nothing to record.
 * - `identified` - one licence this module recognises, and only one.
 * - `ambiguous` - something about terms is in there and this module will not
 *   name it. `reason` and `excerpt` are for the person who has to.
 */
export type LicenceStatementKind = "none" | "identified" | "ambiguous";

export interface LicenceStatement {
  kind: LicenceStatementKind;
  /** An SPDX identifier, set only when `kind` is `identified`. */
  licence: string | null;
  /**
   * Whether a render, which is a derivative work, stays inside the licence. Set
   * only when `kind` is `identified`.
   */
  allowsDerivatives: boolean | null;
  /** Why a person has to read it. Set only when `kind` is `ambiguous`. */
  reason: string | null;
  /** The files the finding rests on, in the order they were given. */
  files: string[];
  /** The lines that matched, so nobody has to open the archive to review this. */
  excerpt: string | null;
}

/**
 * Filenames worth reading, by stem. `LICENSE-MIT.txt` and `readme (old).md`
 * both count. `zlib LICENSE.txt` does not, because the name says whose licence
 * it is and it is not the map's.
 */
const CANDIDATE_STEM = /^(licen[cs]e|copying|copyright|readme|terms|legal)([-_. ].*)?$/i;

/**
 * Extensions a readable statement arrives in. No extension is allowed too,
 * because `LICENSE` and `COPYING` usually have none.
 */
const CANDIDATE_EXTENSIONS = ["", ".txt", ".md", ".markdown", ".rst", ".nfo"];

/**
 * A stem that is itself a claim about terms. A file called `LICENSE` is a
 * statement even when this module cannot read what it says, so one of these can
 * never come back as nothing found. A `readme` can, and usually does.
 */
const CANDIDATE_STEM_IS_A_CLAIM = /^(licen[cs]e|copying|copyright|terms|legal)([-_. ].*)?$/i;

/**
 * Wording that means the file is talking about terms at all.
 *
 * This is the line between `none` and `ambiguous`, and it is what keeps three
 * thousand readmes saying "have fun, thanks for the textures" out of a person's
 * review queue.
 */
const LICENCE_WORDING =
  /licen[cs]e|licen[cs]ed|copyright|\(c\) ?(19|20)\d\d|©|all rights reserved|public domain|creative commons|permission|redistribut|derivative|attribution|royalty|commercial|do not use|ask me|contact me/i;

/**
 * Wording worth quoting back at a person, which is the above plus the licence
 * short names.
 *
 * Wider than {@link LICENCE_WORDING} and used for nothing but the excerpt. A
 * line reading only "CC BY-SA 4.0" is the whole statement and has to appear in
 * the quote, but a bare "BSD" or "Apache" in a readme is too weak to promote a
 * file from nothing found to somebody's review queue.
 */
const QUOTABLE_WORDING = new RegExp(
  `${LICENCE_WORDING.source}|cc0|cc[ _-]?by|\\bgpl\\b|\\bwtfpl\\b|unlicense|\\bbsd\\b|apache|\\bmit\\b|\\bzlib\\b`,
  "i",
);

interface NamedLicence {
  pattern: RegExp;
  id: string;
  /** Whether publishing a render, a derivative work, stays inside it. */
  derivatives: boolean;
}

/**
 * The licences this module will name, matched on wording distinctive enough that
 * a passing mention cannot trigger it. A bare "MIT" is not on the list, because
 * it is a substring of too many ordinary words and the grant sentence is
 * unmistakable.
 *
 * Short on purpose. Anything not here becomes `ambiguous`, which costs a person
 * a minute, and the alternative costs a wrong claim.
 */
const NAMED_LICENCES: NamedLicence[] = [
  {
    pattern: /\bcc0\b|creative commons zero|public domain dedication/,
    id: "CC0-1.0",
    derivatives: true,
  },
  {
    pattern: /do what the fuck you want to public licen[cs]e|\bwtfpl\b/,
    id: "WTFPL",
    derivatives: true,
  },
  {
    pattern:
      /\bthe unlicense\b|this is free and unencumbered software released into the public domain/,
    id: "Unlicense",
    derivatives: true,
  },
  {
    pattern: /permission is hereby granted, free of charge|\bmit licen[cs]e\b/,
    id: "MIT",
    derivatives: true,
  },
  {
    pattern: /apache licen[cs]e,? ?version 2\.0/,
    id: "Apache-2.0",
    derivatives: true,
  },
  {
    pattern:
      /\bzlib licen[cs]e\b|this software is provided 'as-is', without any express or implied warranty/,
    id: "Zlib",
    derivatives: true,
  },
];

/** Collapse to one lowercase line, so a pattern need not care about wrapping. */
function normalise(text: string): string {
  return text.toLowerCase().replace(/\s+/g, " ");
}

function baseName(path: string): string {
  return path.slice(path.lastIndexOf("/") + 1);
}

function stemOf(path: string): string {
  const name = baseName(path);
  const dot = name.lastIndexOf(".");
  return dot <= 0 ? name : name.slice(0, dot);
}

function extensionOf(path: string): string {
  const name = baseName(path);
  const dot = name.lastIndexOf(".");
  return dot <= 0 ? "" : name.slice(dot).toLowerCase();
}

/**
 * Whether one archive entry is worth reading.
 *
 * Root only, and the file's own name has to be about terms. See the note at the
 * top of this file for the corpus evidence behind the depth rule: every licence
 * file below the root in 95 real maps licensed a bundled library or model pack
 * rather than the map.
 */
export function isLicenceCandidatePath(path: string): boolean {
  const slashed = path.replace(/\\/g, "/");
  const clean = slashed.startsWith("./") ? slashed.slice(2) : slashed;
  if (clean === "" || clean.endsWith("/") || clean.includes("/")) return false;
  if (!CANDIDATE_EXTENSIONS.includes(extensionOf(clean))) return false;
  return CANDIDATE_STEM.test(stemOf(clean));
}

/** Every entry in an archive listing worth reading, in the listing's order. */
export function licenceCandidatePaths(paths: readonly string[]): string[] {
  return paths.filter(isLicenceCandidatePath);
}

/**
 * A Creative Commons licence named in the text, if one is.
 *
 * CC is the family that actually turns up on Recoil maps, and the one where the
 * riders change the answer. A non-commercial rider is not a reading question at
 * all - whether the hub counts as non-commercial is somebody's decision - so it
 * comes back ambiguous rather than named. A no-derivatives rider is a reading
 * question with a clear answer: a render is a derivative work, so extraction
 * survives it and rendering does not.
 *
 * An unversioned "CC BY-SA" is ambiguous too. Every version of it would permit
 * what the hub wants, so this is stricter than it needs to be, and it stays
 * strict because writing a version the archive never stated into a
 * defensibility table is the habit worth not having.
 */
function creativeCommons(
  text: string,
): { id: string; derivatives: boolean } | { ambiguous: string } | null {
  const anchor = text.search(/creative commons attribution|\bcc[ _-]?by\b/);
  if (anchor < 0) return null;

  const window = text.slice(Math.max(0, anchor - 20), anchor + 140);

  if (/\bnc\b|non[ -]?commercial/.test(window)) {
    return {
      ambiguous:
        "names a Creative Commons non-commercial licence, and whether the hub counts as non-commercial is a decision rather than a reading",
    };
  }

  const version = window.match(/\b([1-4])\.0\b/)?.[1];
  if (!version) {
    return { ambiguous: "names a Creative Commons Attribution licence but states no version" };
  }

  const noDerivatives = /\bnd\b|no[ -]?deriv/.test(window);
  const shareAlike = /\bsa\b|share[ -]?alike/.test(window);
  const rider = noDerivatives ? "-ND" : shareAlike ? "-SA" : "";

  return { id: `CC-BY${rider}-${version}.0`, derivatives: !noDerivatives };
}

/** The GNU variant the text names, or null when it names none of them. */
function gnuLicence(text: string): NamedLicence | null {
  if (!/gnu general public licen[cs]e/.test(text)) return null;

  const version = /version 3|gpl-?v?3/.test(text)
    ? "3"
    : /version 2|gpl-?v?2/.test(text)
      ? "2"
      : null;
  if (!version) return null;

  const orLater = /any later version|or later/.test(text);
  return {
    pattern: /(?:)/,
    id: `GPL-${version}.0-${orLater ? "or-later" : "only"}`,
    derivatives: true,
  };
}

/** Every named licence the text matches. */
function namedLicences(text: string): NamedLicence[] {
  const hits = NAMED_LICENCES.filter((known) => known.pattern.test(text));

  // BSD and GPL each need a second read to pin the variant down, so they are
  // functions rather than table rows.
  if (/redistribution and use in source and binary forms/.test(text)) {
    hits.push({
      pattern: /(?:)/,
      id: /neither the name/.test(text) ? "BSD-3-Clause" : "BSD-2-Clause",
      derivatives: true,
    });
  }

  const gnu = gnuLicence(text);
  if (gnu) hits.push(gnu);

  return hits;
}

/** Up to eight lines a person would want to see, trimmed and capped. */
function excerptOf(files: readonly ArchiveTextFile[]): string | null {
  const lines: string[] = [];

  for (const file of files) {
    for (const raw of file.text.split(/\r?\n/)) {
      const line = raw.trim();
      if (line === "" || !QUOTABLE_WORDING.test(line)) continue;
      const clipped = line.length > 160 ? `${line.slice(0, 157)}...` : line;
      if (!lines.includes(clipped)) lines.push(clipped);
      if (lines.length === 8) break;
    }
    if (lines.length === 8) break;
  }

  if (lines.length === 0) return null;
  const joined = lines.join("\n");
  return joined.length > 600 ? `${joined.slice(0, 597)}...` : joined;
}

const NOTHING_FOUND: LicenceStatement = {
  kind: "none",
  licence: null,
  allowsDerivatives: null,
  reason: null,
  files: [],
  excerpt: null,
};

/**
 * What the archive's own text says about its terms.
 *
 * One recognised licence and no complications is `identified`. Two different
 * licences, a recognised one alongside a rider this cannot rule on, wording
 * about terms that matches nothing, a file named `LICENSE` this could not read,
 * or a candidate too large to read in full, are all `ambiguous`. Anything else
 * is `none`.
 *
 * A file named `LICENSE` never comes back as `none`, whatever is in it. Its name
 * is already the claim.
 */
export function classifyLicenceStatement(files: readonly ArchiveTextFile[]): LicenceStatement {
  const ids = new Map<string, boolean>();
  const reasons: string[] = [];
  const involved: string[] = [];

  for (const file of files) {
    const text = normalise(file.text);
    const named = namedLicences(text);
    const cc = creativeCommons(text);
    const claimedByName = CANDIDATE_STEM_IS_A_CLAIM.test(stemOf(file.path));
    const wording = LICENCE_WORDING.test(text);
    const namesNothing = named.length === 0 && !cc;

    for (const known of named) ids.set(known.id, known.derivatives);
    if (cc && "id" in cc) ids.set(cc.id, cc.derivatives);
    if (cc && "ambiguous" in cc) reasons.push(cc.ambiguous);

    if (file.truncated) {
      reasons.push(`${file.path} was too large to read in full`);
    } else if (claimedByName && namesNothing) {
      reasons.push(`${file.path} is named as a licence but states none this reader knows`);
    } else if (wording && namesNothing) {
      reasons.push(`${file.path} discusses terms in wording this reader does not recognise`);
    }

    if (!namesNothing || claimedByName || wording || file.truncated) involved.push(file.path);
  }

  if (ids.size === 0 && reasons.length === 0) return NOTHING_FOUND;

  const excerpt = excerptOf(files);

  if (ids.size === 1 && reasons.length === 0) {
    const [[id, derivatives]] = [...ids];
    return {
      kind: "identified",
      licence: id,
      allowsDerivatives: derivatives,
      reason: null,
      files: involved,
      excerpt,
    };
  }

  if (ids.size > 1) {
    reasons.unshift(`names more than one licence: ${[...ids.keys()].sort().join(", ")}`);
  } else if (ids.size === 1) {
    reasons.unshift(`names ${[...ids.keys()][0]} alongside wording this reader cannot rule on`);
  }

  return {
    kind: "ambiguous",
    licence: null,
    allowsDerivatives: null,
    reason: reasons.join(", and "),
    files: involved,
    excerpt,
  };
}

/**
 * A row somebody could commit, in the table's own column names.
 *
 * Not a row that gets written. Nobody holds insert on `asset_licence` -
 * `service_role` has select only - so a licence decision is a migration, and
 * this is the draft of one. That is the right shape anyway: every proposal here
 * rests on a pattern match against a stranger's readme, and a person reading it
 * before it becomes permanent is the check that makes the pattern matching
 * affordable.
 */
export interface MapLicenceProposal {
  map_name: string;
  licence: string;
  notes: string;
  checked_by: string;
  redistribute_extracted: AssetRedistribution;
  redistribute_rendered: AssetRedistribution;
  /**
   * Whether committing this takes away something the blanket map row currently
   * grants. True for a no-derivatives licence, whose renders stop being allowed.
   * The reviewer wants to see these first.
   */
  narrowsTheDefault: boolean;
}

/** `notes` has a 4096 character check constraint on it. */
const MAX_NOTES = 4096;

const DERIVATIVES_NOTE =
  "This row says the same thing the blanket map row says, and rests it on the mapper's own words rather than on a maintainer decision.";

const NO_DERIVATIVES_NOTE =
  "The licence carries a no-derivatives rider. A render is a derivative work drawn from the map, so rendering is refused here even though the blanket map row allows it. Extraction of images the archive already contains is unaffected.";

/**
 * Turn an identified statement into a proposed row, or into nothing.
 *
 * Only `identified` produces a proposal. `ambiguous` goes to a person as it
 * stands, because the whole content of that finding is that a machine should not
 * write it down, and `none` produces nothing because the blanket row already
 * answers correctly for that map.
 *
 * `mapName` is the full canonical name the engine reports, version string and
 * all, and is never split. `archive` is the file the statement was read out of.
 * It goes in the notes as the only address the evidence has, since there is no
 * URL for a text file inside somebody's `.sd7`.
 */
export function proposeMapLicence(
  mapName: string,
  archive: string,
  statement: LicenceStatement,
): MapLicenceProposal | null {
  if (statement.kind !== "identified" || !statement.licence) return null;

  const rendered: AssetRedistribution = statement.allowsDerivatives ? "allowed" : "denied";

  const notes = [
    `Read out of the map archive by ${LICENCE_CHECKED_BY}.`,
    "",
    `Archive: ${archive}`,
    `File: ${statement.files.join(", ")}`,
    "",
    statement.excerpt ? `Quoted from it:\n\n${statement.excerpt}` : "No quotable line.",
    "",
    statement.allowsDerivatives ? DERIVATIVES_NOTE : NO_DERIVATIVES_NOTE,
  ].join("\n");

  return {
    map_name: mapName,
    licence: statement.licence,
    notes: notes.length > MAX_NOTES ? `${notes.slice(0, MAX_NOTES - 3)}...` : notes,
    checked_by: LICENCE_CHECKED_BY,
    redistribute_extracted: "allowed",
    redistribute_rendered: rendered,
    narrowsTheDefault: rendered !== "allowed",
  };
}

/**
 * Dollar quoting tag for the emitted SQL. Chosen so it cannot appear in a
 * mapper's readme by accident, and checked rather than assumed.
 */
const SQL_TAG = "$licence_evidence$";

function quote(value: string): string {
  if (value.includes(SQL_TAG)) throw new Error(`Cannot quote a value containing ${SQL_TAG}`);
  return `${SQL_TAG}${value}${SQL_TAG}`;
}

/**
 * The proposals as the body of a migration, for a person to read, edit and
 * commit.
 *
 * `on conflict do nothing`, because the unique index allows one row per map and
 * a map somebody has already ruled on by hand should not be quietly replaced by
 * a pattern match.
 */
export function mapLicenceMigrationSql(proposals: readonly MapLicenceProposal[]): string {
  if (proposals.length === 0) return "-- No map stated its own licence. Nothing to insert.\n";

  return proposals
    .map(
      (row) =>
        "insert into public.asset_licence\n" +
        "  (map_name, licence, checked_by, redistribute_extracted, redistribute_rendered, notes)\n" +
        "values (\n" +
        `  ${quote(row.map_name)},\n` +
        `  ${quote(row.licence)},\n` +
        `  ${quote(row.checked_by)},\n` +
        `  '${row.redistribute_extracted}',\n` +
        `  '${row.redistribute_rendered}',\n` +
        `  ${quote(row.notes)}\n` +
        ")\non conflict do nothing;\n",
    )
    .join("\n");
}
