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
const SOURCE_PATH = "src/container/container.ts";
const VENDORED = "lib/container/container.ts";
const RECORD = "lib/container/source.json";

interface SourceRecord {
  repo: string;
  ref: string;
  path: string;
  blobSha: string;
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

async function fetchUpstream(): Promise<{ text: string; blobSha: string }> {
  const url = `https://raw.githubusercontent.com/${REPO}/${REF}/${SOURCE_PATH}`;
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
const upstream = await fetchUpstream();
const record = await readRecord();

if (check) {
  if (!record) {
    console.error(`No ${RECORD}. Run: bun run sync:container`);
    process.exit(1);
  }
  const vendored = await Bun.file(VENDORED).text();
  const vendoredSha = await gitBlobSha(vendored);

  if (vendoredSha !== record.blobSha) {
    console.error(
      `${VENDORED} has been edited locally. It is vendored from ${REPO} and must not be changed here.\n` +
        `Change it in ${REPO} instead, then run: bun run sync:container`,
    );
    process.exit(1);
  }
  if (upstream.blobSha !== record.blobSha) {
    console.error(
      `${REPO} ${SOURCE_PATH} has moved on since this copy was taken.\n` +
        `  vendored ${record.blobSha}\n  upstream ${upstream.blobSha}\n` +
        `Run: bun run sync:container`,
    );
    process.exit(1);
  }
  console.log(`In sync with ${REPO} ${SOURCE_PATH} (${record.blobSha}).`);
  process.exit(0);
}

// Compare what is on disk, not just what was recorded. A locally edited file is
// the case `--check` tells you to run this to fix, so skipping the write when the
// record happens to match upstream would leave it broken with nothing to do.
const onDisk = await Bun.file(VENDORED)
  .text()
  .then(gitBlobSha)
  .catch(() => null);

if (record?.blobSha === upstream.blobSha && onDisk === upstream.blobSha) {
  console.log(`Already at ${upstream.blobSha}, nothing to write.`);
  process.exit(0);
}

await Bun.write(VENDORED, upstream.text);
const next: SourceRecord = {
  repo: REPO,
  ref: REF,
  path: SOURCE_PATH,
  blobSha: upstream.blobSha,
};
await Bun.write(RECORD, `${JSON.stringify(next, null, 2)}\n`);
console.log(
  `Wrote ${VENDORED} from ${REPO} ${SOURCE_PATH}\n  ${record?.blobSha ?? "(new)"} -> ${upstream.blobSha}`,
);
