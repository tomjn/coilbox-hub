import { afterAll, expect, test } from "bun:test";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { homedir, tmpdir } from "node:os";
import { join } from "node:path";
import { strToU8, zipSync } from "fflate";
import {
  readArchiveLicence,
  SEVEN_ZIP_READER,
  sevenZipReaderAvailable,
} from "./archiveLicence";

const work = mkdtempSync(join(tmpdir(), "map-archive-licence-"));

afterAll(() => rmSync(work, { recursive: true }));

/**
 * The layout every fixture uses: a real statement at the root, and the trap the
 * corpus is full of, a bundled library's own licence further down.
 */
const CONTENTS: Record<string, string> = {
  "mapinfo.lua": "return { name = 'Fixture', author = 'nobody' }\n",
  "readme.txt": "Fixture map.\n\nReleased under CC BY-SA 4.0.\n",
  "libs/s11n/LICENSE": "Permission is hereby granted, free of charge, to any person\n",
  "libs/lcs/zlib LICENSE.txt": "zlib License. This software is provided 'as-is'.\n",
  "maps/fixture.smf": "not really a map\n",
};

function zipFixture(name: string, contents = CONTENTS): string {
  const path = join(work, name);
  const entries = Object.fromEntries(
    Object.entries(contents).map(([entry, text]) => [entry, strToU8(text)]),
  );
  writeFileSync(path, zipSync(entries));
  return path;
}

function directoryFixture(name: string, contents = CONTENTS): string {
  const root = join(work, name);
  for (const [entry, text] of Object.entries(contents)) {
    const at = join(root, entry);
    mkdirSync(at.slice(0, at.lastIndexOf("/")), { recursive: true });
    writeFileSync(at, text);
  }
  return root;
}

/** A real 7-zip archive, if this machine can write one. */
function sevenZipFixture(name: string, contents = CONTENTS): string | null {
  const source = directoryFixture(`${name}.source`, contents);
  const path = join(work, name);
  try {
    execFileSync("7zz", ["a", "-bso0", "-bsp0", path, join(source, "*")]);
  } catch {
    return null;
  }
  return path;
}

const canWriteSevenZip = sevenZipFixture("probe.sd7", { "readme.txt": "probe\n" }) !== null;
const canReadSevenZip = await sevenZipReaderAvailable();

test(`the .sd7 reader ${SEVEN_ZIP_READER} is on this machine`, () => {
  // Not an assertion, a report. The whole point of the check is that a machine
  // without it gets a named error rather than a spawn failure, and the test
  // below covers that path.
  console.log(
    `${SEVEN_ZIP_READER} available: ${canReadSevenZip}. 7zz available to write fixtures: ${canWriteSevenZip}.`,
  );
  expect(typeof canReadSevenZip).toBe("boolean");
});

test("a .sdz is read in process and only the root statement counts", async () => {
  const found = await readArchiveLicence(zipFixture("fixture.sdz"));

  expect(found.error).toBeNull();
  expect(found.kind).toBe("sdz");
  expect(found.statement?.kind).toBe("identified");
  expect(found.statement?.licence).toBe("CC-BY-SA-4.0");
  expect(found.statement?.files).toEqual(["readme.txt"]);
});

test("a .sdd is a directory the engine loads the same way", async () => {
  const found = await readArchiveLicence(directoryFixture("fixture.sdd"));

  expect(found.error).toBeNull();
  expect(found.kind).toBe("sdd");
  expect(found.statement?.licence).toBe("CC-BY-SA-4.0");
  expect(found.statement?.files).toEqual(["readme.txt"]);
});

test("an archive with nothing at its root says nothing", async () => {
  const found = await readArchiveLicence(
    zipFixture("bare.sdz", {
      "mapinfo.lua": CONTENTS["mapinfo.lua"],
      "libs/s11n/LICENSE": CONTENTS["libs/s11n/LICENSE"],
    }),
  );

  expect(found.statement?.kind).toBe("none");
});

/**
 * A picture called `readme.txt` is not a statement, and running licence patterns
 * over decoded binary is a way to match wording nobody wrote.
 */
test("a binary entry with a text name is skipped", async () => {
  const path = join(work, "binary.sdz");
  writeFileSync(
    path,
    zipSync({ "readme.txt": new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x00, 0x01]) }),
  );

  expect((await readArchiveLicence(path)).statement?.kind).toBe("none");
});

test("a file that is not one of the three formats is an error, not a guess", async () => {
  const found = await readArchiveLicence(join(work, "fixture.zip"));

  expect(found.kind).toBeNull();
  expect(found.statement).toBeNull();
  expect(found.error).toContain(".sd7");
});

test("a missing archive is reported rather than thrown", async () => {
  const found = await readArchiveLicence(join(work, "there-is-no-such-map.sdz"));

  expect(found.statement).toBeNull();
  expect(found.error).not.toBeNull();
});

test.if(canWriteSevenZip && canReadSevenZip)("a .sd7 reads the same as a .sdz", async () => {
  const path = sevenZipFixture("fixture.sd7");
  const found = await readArchiveLicence(path!);

  expect(found.error).toBeNull();
  expect(found.kind).toBe("sd7");
  expect(found.statement?.licence).toBe("CC-BY-SA-4.0");
  expect(found.statement?.files).toEqual(["readme.txt"]);
});

test.if(!canReadSevenZip)("without the reader a .sd7 fails by name", async () => {
  const found = await readArchiveLicence(join(work, "anything.sd7"));

  expect(found.statement).toBeNull();
  expect(found.error).toContain(SEVEN_ZIP_READER);
});

/**
 * The real corpus, when it is on this machine. `Tumult.sd7` is the only one of
 * the 95 archives with a root readme, and the other two both carry a bundled
 * library's licence deep in the tree. All three have to come back as nothing,
 * because nothing is what they say about their own terms.
 */
const CORPUS = join(homedir(), ".spring", "maps");
const realArchives = [
  "Tumult.sd7",
  "comet_catcher_remake_1.8.sd7",
  "moor_v4.sdz",
].map((name) => join(CORPUS, name));

test.if(canReadSevenZip && realArchives.every(existsSync))(
  "real map archives state nothing, including the ones full of bundled licences",
  async () => {
    for (const archive of realArchives) {
      const found = await readArchiveLicence(archive);
      expect(found.error).toBeNull();
      expect(found.statement?.kind).toBe("none");
    }
  },
);
