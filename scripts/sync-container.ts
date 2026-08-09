/**
 * Keep `lib/container/container.ts` byte identical to the copy in coilbox.
 *
 * The hub has to read exactly the format the app writes. If the two definitions
 * drift, the failure is silent: the gallery accepts something the app will then
 * refuse to import, and nobody finds out until a user complains that a link does
 * not work. So the file is vendored rather than reimplemented, and this script
 * is the only thing allowed to write it.
 *
 *   bun run sync:container           write the vendored copy from coilbox main
 *   bun run sync:container --check   report drift and change nothing
 *
 * `--check` is what CI runs. It compares the blob hash on coilbox main against
 * the one recorded in `source.json`, so it detects both an upstream change and a
 * local edit to the vendored file.
 */

export {}; // top level await needs this file to be a module

const REPO = "tomjn/coilbox";
const REF = "main";
const SOURCE_DIR = "src/container";
const VENDOR_DIR = "lib/container";
const RECORD = "lib/container/source.json";

/** Every file container.ts needs, transitively, from src/container in coilbox.
 * container.ts imports ./gameIdentity (issue #1335), and gameIdentity.ts
 * imports nothing else from the directory. Update this list by hand if
 * upstream's imports change again, the script does not walk imports itself. */
const FILES = ["container.ts", "gameIdentity.ts"];

interface SourceRecord {
  repo: string;
  ref: string;
  dir: string;
  files: Record<string, string>; // filename -> blob sha
}

/** Git's blob hash for some content, so a local edit is caught by the same
 * comparison as an upstream change. Matches what the GitHub API reports. */
async function gitBlobSha(content: string): Promise<string> {
  const body = new TextEncoder().encode(content);
  const header = new TextEncoder().encode(`blob ${body.length}\0`);
  const joined = new Uint8Array(header.length + body.length);
  joined.set(header);
  joined.set(body, header.length);
  const digest = await crypto.subtle.digest("SHA-1", joined);
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

async function fetchUpstream(
  file: string,
): Promise<{ text: string; blobSha: string }> {
  const url = `https://raw.githubusercontent.com/${REPO}/${REF}/${SOURCE_DIR}/${file}`;
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Could not read ${url}: ${response.status}`);
  }
  const text = await response.text();
  return { text, blobSha: await gitBlobSha(text) };
}

async function readRecord(): Promise<SourceRecord | null> {
  const file = Bun.file(RECORD);
  return (await file.exists()) ? ((await file.json()) as SourceRecord) : null;
}

const check = process.argv.includes("--check");
const upstream = new Map(
  await Promise.all(
    FILES.map(async (file) => [file, await fetchUpstream(file)] as const),
  ),
);
const record = await readRecord();

if (check) {
  if (!record) {
    console.error(`No ${RECORD}. Run: bun run sync:container`);
    process.exit(1);
  }
  for (const file of FILES) {
    const vendoredPath = `${VENDOR_DIR}/${file}`;
    const vendored = await Bun.file(vendoredPath).text();
    const vendoredSha = await gitBlobSha(vendored);
    const recordedSha = record.files[file];

    if (vendoredSha !== recordedSha) {
      console.error(
        `${vendoredPath} has been edited locally. It is vendored from ${REPO} and must not be changed here.\n` +
          `Change it in ${REPO} instead, then run: bun run sync:container`,
      );
      process.exit(1);
    }
    const upstreamSha = upstream.get(file)!.blobSha;
    if (upstreamSha !== recordedSha) {
      console.error(
        `${REPO} ${SOURCE_DIR}/${file} has moved on since this copy was taken.\n` +
          `  vendored ${recordedSha}\n  upstream ${upstreamSha}\n` +
          `Run: bun run sync:container`,
      );
      process.exit(1);
    }
  }
  console.log(`In sync with ${REPO} ${SOURCE_DIR} (${FILES.join(", ")}).`);
  process.exit(0);
}

const next: SourceRecord = {
  repo: REPO,
  ref: REF,
  dir: SOURCE_DIR,
  files: {},
};
let wroteAny = false;

for (const file of FILES) {
  const vendoredPath = `${VENDOR_DIR}/${file}`;
  const { text, blobSha } = upstream.get(file)!;
  next.files[file] = blobSha;

  // Compare what is on disk, not just what was recorded. A locally edited file
  // is the case `--check` tells you to run this to fix, so skipping the write
  // when the record happens to match upstream would leave it broken with
  // nothing to do.
  const onDisk = await Bun.file(vendoredPath)
    .text()
    .then(gitBlobSha)
    .catch(() => null);

  if (record?.files?.[file] === blobSha && onDisk === blobSha) {
    console.log(`${vendoredPath} already at ${blobSha}, nothing to write.`);
    continue;
  }

  await Bun.write(vendoredPath, text);
  wroteAny = true;
  console.log(
    `Wrote ${vendoredPath} from ${REPO} ${SOURCE_DIR}/${file}\n  ${record?.files?.[file] ?? "(new)"} -> ${blobSha}`,
  );
}

if (wroteAny) {
  await Bun.write(RECORD, `${JSON.stringify(next, null, 2)}\n`);
}
