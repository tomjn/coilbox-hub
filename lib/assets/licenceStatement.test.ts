import { expect, test } from "bun:test";
import {
  classifyLicenceStatement,
  isLicenceCandidatePath,
  licenceCandidatePaths,
  mapLicenceMigrationSql,
  proposeMapLicence,
  type ArchiveTextFile,
} from "./licenceStatement";

function file(path: string, text: string, truncated = false): ArchiveTextFile {
  return { path, text, truncated };
}

function classify(path: string, text: string) {
  return classifyLicenceStatement([file(path, text)]);
}

/**
 * The paths below are real entries from the maintainer's `~/.spring/maps`, and
 * the rejections matter more than the acceptances. Sixteen of those 95 archives
 * carry a licence file, every one of them belongs to a bundled library, model
 * pack or texture pack, and matching any of them would write that library's
 * licence into the map's row.
 */
test("only the archive root is worth reading", () => {
  expect(isLicenceCandidatePath("readme.txt")).toBe(true);
  expect(isLicenceCandidatePath("LICENSE")).toBe(true);

  expect(isLicenceCandidatePath("libs/s11n/LICENSE")).toBe(false);
  expect(isLicenceCandidatePath("libs/lcs/README.md")).toBe(false);
  expect(isLicenceCandidatePath("objects3d/LowpolyPineTrees/License.txt")).toBe(false);
  expect(isLicenceCandidatePath("unittextures/LICENSE.txt")).toBe(false);
  expect(isLicenceCandidatePath("features/LICENSE.txt")).toBe(false);
  expect(isLicenceCandidatePath("maps/source/credit.txt")).toBe(false);
});

/**
 * `zlib LICENSE.txt` is the one that would slip past a naive contains match. Its
 * own name says whose licence it is, and it is not the map's.
 */
test("a filename that names somebody else's licence is not the map's", () => {
  expect(isLicenceCandidatePath("zlib LICENSE.txt")).toBe(false);
  expect(isLicenceCandidatePath("LICENSE-MIT.txt")).toBe(true);
  expect(isLicenceCandidatePath("readme (old).md")).toBe(true);
});

test("only text extensions, and no extension, are read", () => {
  expect(isLicenceCandidatePath("licence.txt")).toBe(true);
  expect(isLicenceCandidatePath("COPYING")).toBe(true);
  expect(isLicenceCandidatePath("readme.md")).toBe(true);
  expect(isLicenceCandidatePath("readme.png")).toBe(false);
  expect(isLicenceCandidatePath("license.lua")).toBe(false);
});

test("windows separators and a leading dot slash are the same paths", () => {
  expect(isLicenceCandidatePath("libs\\s11n\\LICENSE")).toBe(false);
  expect(isLicenceCandidatePath("./readme.txt")).toBe(true);
  expect(licenceCandidatePaths(["mapinfo.lua", "readme.txt", "maps/", "libs/s11n/LICENSE"])).toEqual([
    "readme.txt",
  ]);
});

/**
 * The whole content of `Tumult.sd7/readme.txt`, the only root readme in the
 * corpus. It is a joke about palm trees, and reporting it as anything other than
 * nothing would put a person's afternoon into reviewing it.
 */
test("a readme that says nothing about terms is nothing", () => {
  const found = classify(
    "readme.txt",
    "damn somebody read this file\n\nthey are probably going to jack my palm trees 8)\n",
  );
  expect(found.kind).toBe("none");
  expect(found.files).toEqual([]);
});

test("an empty archive is nothing", () => {
  expect(classifyLicenceStatement([]).kind).toBe("none");
});

test("a versioned Creative Commons licence is named", () => {
  const found = classify("readme.txt", "This map is released under CC BY-SA 4.0. Have fun.");
  expect(found.kind).toBe("identified");
  expect(found.licence).toBe("CC-BY-SA-4.0");
  expect(found.allowsDerivatives).toBe(true);
  expect(found.excerpt).toContain("CC BY-SA 4.0");
});

test("the long spelling of the same licence reads the same", () => {
  const found = classify(
    "LICENSE",
    "Creative Commons Attribution Share-Alike 4.0 International",
  );
  expect(found.licence).toBe("CC-BY-SA-4.0");
});

/**
 * A render is a derivative work drawn from the map, so a no-derivatives rider
 * takes rendering away while leaving extraction of the shipped images alone.
 * This is the one finding that makes a map worse off than the blanket default,
 * and it is the reason the reader bothers with riders at all.
 */
test("a no-derivatives rider keeps extraction and loses rendering", () => {
  const found = classify("readme.txt", "Licensed under CC BY-ND 4.0.");
  expect(found.kind).toBe("identified");
  expect(found.licence).toBe("CC-BY-ND-4.0");
  expect(found.allowsDerivatives).toBe(false);

  const proposal = proposeMapLicence("Some Map 1.0", "some_map_1.0.sd7", found);
  expect(proposal?.redistribute_extracted).toBe("allowed");
  expect(proposal?.redistribute_rendered).toBe("denied");
  expect(proposal?.narrowsTheDefault).toBe(true);
});

/**
 * Whether the hub counts as non-commercial is a decision somebody has to make,
 * not something a regular expression gets to settle.
 */
test("a non-commercial rider is handed to a person", () => {
  const found = classify("readme.txt", "Released under CC BY-NC-SA 4.0.");
  expect(found.kind).toBe("ambiguous");
  expect(found.reason).toContain("non-commercial");
  expect(proposeMapLicence("Some Map 1.0", "some_map_1.0.sd7", found)).toBeNull();
});

test("an unversioned Creative Commons licence is handed to a person", () => {
  const found = classify("readme.txt", "This map is CC-BY, do what you like.");
  expect(found.kind).toBe("ambiguous");
  expect(found.reason).toContain("states no version");
});

test("the plain software licences are named", () => {
  expect(classify("LICENSE", "Permission is hereby granted, free of charge, to any person").licence).toBe(
    "MIT",
  );
  expect(classify("LICENSE", "Apache License, Version 2.0, January 2004").licence).toBe(
    "Apache-2.0",
  );
  expect(classify("readme.txt", "Textures are CC0, do as you please.").licence).toBe("CC0-1.0");
  expect(
    classify("COPYING", "GNU General Public License version 3, or any later version").licence,
  ).toBe("GPL-3.0-or-later");
  expect(classify("COPYING", "GNU General Public License, version 2").licence).toBe(
    "GPL-2.0-only",
  );
});

test("two different licences in one archive is a person's problem", () => {
  const found = classifyLicenceStatement([
    file("LICENSE", "Permission is hereby granted, free of charge, to any person"),
    file("readme.txt", "Everything here is CC BY-SA 4.0."),
  ]);
  expect(found.kind).toBe("ambiguous");
  expect(found.reason).toContain("more than one licence");
  expect(found.reason).toContain("CC-BY-SA-4.0");
  expect(found.reason).toContain("MIT");
});

/**
 * The name is already a claim, so this can never come back as nothing even when
 * the text inside is unreadable to this module.
 */
test("a file called LICENSE always reaches a person", () => {
  const found = classify("LICENSE", "You may use this map in the BAR ladder only.");
  expect(found.kind).toBe("ambiguous");
  expect(found.reason).toContain("named as a licence");
});

test("licence wording this reader cannot name reaches a person", () => {
  const found = classify(
    "readme.txt",
    "All rights reserved. Ask me before you reupload this anywhere.",
  );
  expect(found.kind).toBe("ambiguous");
  expect(found.excerpt).toContain("All rights reserved");
});

/**
 * Truncation must never be able to turn into a "no statement" answer, because
 * the statement could be in the part nobody read.
 */
test("a candidate too large to read in full is ambiguous, not nothing", () => {
  const found = classifyLicenceStatement([file("readme.txt", "nothing to see", true)]);
  expect(found.kind).toBe("ambiguous");
  expect(found.reason).toContain("too large");
});

/**
 * An archive that agrees with the default still gets a row, because the point is
 * that the record says what the map said rather than what the maintainer decided.
 */
test("a proposal for a permissive licence matches the default and cites the archive", () => {
  const found = classify("readme.txt", "Released under CC BY 4.0 by the author.");
  const proposal = proposeMapLicence("Comet Catcher Remake 1.8", "comet_catcher_remake_1.8.sd7", found);

  expect(proposal).not.toBeNull();
  expect(proposal?.map_name).toBe("Comet Catcher Remake 1.8");
  expect(proposal?.licence).toBe("CC-BY-4.0");
  expect(proposal?.redistribute_extracted).toBe("allowed");
  expect(proposal?.redistribute_rendered).toBe("allowed");
  expect(proposal?.narrowsTheDefault).toBe(false);
  expect(proposal?.notes).toContain("comet_catcher_remake_1.8.sd7");
  expect(proposal?.notes).toContain("readme.txt");
});

test("nothing found and nothing certain both propose nothing", () => {
  const nothing = classify("readme.txt", "have fun");
  const unsure = classify("readme.txt", "All rights reserved.");
  expect(proposeMapLicence("Map 1.0", "map.sd7", nothing)).toBeNull();
  expect(proposeMapLicence("Map 1.0", "map.sd7", unsure)).toBeNull();
});

/** The constraints the migration puts on the columns this writes. */
test("a proposal fits the columns it is destined for", () => {
  const found = classify("readme.txt", `CC BY-SA 4.0\n${"licence ".repeat(2000)}`);
  const proposal = proposeMapLicence("Map 1.0", "map.sd7", found);
  expect(proposal!.licence.length).toBeLessThanOrEqual(128);
  expect(proposal!.checked_by.length).toBeLessThanOrEqual(128);
  expect(proposal!.notes.length).toBeLessThanOrEqual(4096);
});

test("the migration body quotes a map name a person could not have escaped", () => {
  const found = classify("readme.txt", "CC BY 4.0");
  const proposal = proposeMapLicence("O'Neill's Map \"v2\" 1.0", "map.sd7", found)!;
  const sql = mapLicenceMigrationSql([proposal]);

  expect(sql).toContain("insert into public.asset_licence");
  expect(sql).toContain("$licence_evidence$O'Neill's Map \"v2\" 1.0$licence_evidence$");
  expect(sql).toContain("on conflict do nothing");
});

test("no proposals is a migration body that says so rather than an empty file", () => {
  expect(mapLicenceMigrationSql([])).toContain("Nothing to insert");
});

/**
 * The dollar quoting tag is the only thing standing between a mapper's readme
 * and the SQL around it, so a readme containing the tag has to stop the emitter
 * rather than escape it.
 */
test("a value carrying the quoting tag refuses to be emitted", () => {
  const found = classify("readme.txt", "CC BY 4.0");
  const proposal = proposeMapLicence("$licence_evidence$", "map.sd7", found)!;
  expect(() => mapLicenceMigrationSql([proposal])).toThrow("licence_evidence");
});
