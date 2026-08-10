/**
 * Keep the files vendored from coilbox byte identical to their originals.
 *
 * Two groups, for the same reason. The hub has to read exactly the format the
 * app writes, and draw exactly the galaxy the app generates. If either
 * definition drifts the failure is silent: the gallery accepts something the
 * app will refuse to import, or it draws a galaxy that is not the one the
 * challenge produces, and nobody finds out until a user complains. So the
 * files are vendored rather than reimplemented, and this script is the only
 * thing allowed to write them.
 *
 *   bun run sync:vendor           write the vendored copies from coilbox main
 *   bun run sync:vendor --check   report drift and change nothing
 *
 * `--check` is what CI runs. It compares the blob hash on coilbox main against
 * the one recorded in each group's `source.json`, so it detects both an
 * upstream change and a local edit to a vendored file.
 */

export {}; // top level await needs this file to be a module

const REPO = "tomjn/coilbox";
const REF = "main";

interface VendorGroup {
  /** Directory in coilbox the files come from. */
  dir: string;
  /** Directory here they are written to, alongside a `source.json` record. */
  vendor: string;
  /** Files to vendor, relative to both directories. Kept honest by the import
   * walk below rather than by hand, which is how it went stale twice (#71). */
  files: string[];
  /** Paths here that a vendored file may import without being vendored
   * themselves, each mapped to why that is deliberate. */
  externals?: Record<string, string>;
  /** Constants a local, unvendored file restates. Checked against upstream so
   * a change there goes red rather than silently changing what we draw. */
  constants?: { file: string; values: Record<string, string> };
}

const GROUPS: VendorGroup[] = [
  {
    dir: "src/container",
    vendor: "lib/container",
    files: ["container.ts", "gameIdentity.ts", "shortnames.ts"],
  },
  {
    // The conquest generator, so a challenge's galaxy can be drawn from its
    // seed (#76). Same seed, same graph: positions, lanes and starting
    // territory are settled before the generator touches installed content.
    dir: "src/conquest",
    vendor: "lib/conquest",
    files: [
      "generate.ts",
      "names.ts",
      "rng.ts",
      "realstars/index.ts",
      "realstars/catalogue.json",
    ],
    externals: {
      // Upstream's model.ts reaches campaign, scenario, play and content, and
      // through those the Tauri plugin bindings, which is roughly ten thousand
      // lines the hub cannot compile. `lib/conquest/model.ts` is a hand
      // written subset instead: the two constants and the types the generator
      // uses. Type drift is self policing, since a generator that starts
      // writing a new field fails typecheck the moment it is synced. The
      // values are not, so they are checked below.
      "lib/conquest/model.ts": "a hand written subset of upstream's model.ts",
    },
    constants: {
      file: "model.ts",
      values: { NEUTRAL: '"neutral"', MAX_DIFFICULTY: "5" },
    },
  },
];

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

async function fetchUpstream(dir: string, file: string): Promise<string> {
  const url = `https://raw.githubusercontent.com/${REPO}/${REF}/${dir}/${file}`;
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Could not read ${url}: ${response.status}`);
  }
  return response.text();
}

function fail(message: string): never {
  console.error(message);
  process.exit(1);
}

/** Every path a vendored file is allowed to import. Collected across all
 * groups, since the question is whether an import lands on something this repo
 * actually has, not whether it lands inside its own group. */
const VENDORED = new Set(
  GROUPS.flatMap((g) => g.files.map((file) => `${g.vendor}/${file}`)),
);
const EXTERNALS = new Set(
  GROUPS.flatMap((g) => Object.keys(g.externals ?? {})),
);

/** Resolve a relative import the way the bundler will, to a path in this repo. */
function resolveImport(from: string, specifier: string): string | null {
  const base = `${from}/../${specifier}`
    .split("/")
    .reduce<string[]>((parts, part) => {
      if (part === "." || part === "") return parts;
      if (part === "..") return parts.slice(0, -1);
      return [...parts, part];
    }, [])
    .join("/");
  const candidates = [base, `${base}.ts`, `${base}/index.ts`, `${base}.json`];
  return (
    candidates.find((c) => VENDORED.has(c) || EXTERNALS.has(c)) ?? null
  );
}

/**
 * Every relative import in a vendored file must land on another vendored file
 * or on a declared external. Without this the file list is only as good as
 * whoever last read upstream's imports, which is how the check went red on
 * every branch at once when gameIdentity.ts grew an import (#71).
 */
function checkImports(group: VendorGroup, contents: Map<string, string>) {
  for (const [file, text] of contents) {
    if (!file.endsWith(".ts")) continue;
    for (const match of text.matchAll(/from\s+"(\.[^"]+)"/g)) {
      const specifier = match[1];
      if (resolveImport(`${group.vendor}/${file}`, specifier)) continue;
      fail(
        `${group.vendor}/${file} imports ${specifier}, which is neither vendored nor a declared external.\n` +
          `Add it to the group's files in scripts/sync-vendor.ts, or declare why it is external.`,
      );
    }
  }
}

/** Confirm the constants our own copy restates still read the same upstream. */
async function checkConstants(group: VendorGroup) {
  if (!group.constants) return;
  const { file, values } = group.constants;
  const text = await fetchUpstream(group.dir, file);
  for (const [name, value] of Object.entries(values)) {
    if (!text.includes(`export const ${name} = ${value};`)) {
      fail(
        `${REPO} ${group.dir}/${file} no longer declares ${name} as ${value}.\n` +
          `${group.vendor}/model.ts restates it, so update both together.`,
      );
    }
  }
}

const check = process.argv.includes("--check");

for (const group of GROUPS) {
  const record = `${group.vendor}/source.json`;
  const upstream = new Map(
    await Promise.all(
      group.files.map(
        async (file) =>
          [file, await fetchUpstream(group.dir, file)] as const,
      ),
    ),
  );
  const recordFile = Bun.file(record);
  const previous = (await recordFile.exists())
    ? ((await recordFile.json()) as SourceRecord)
    : null;

  if (check) {
    if (!previous) fail(`No ${record}. Run: bun run sync:vendor`);
    const local = new Map<string, string>();
    for (const file of group.files) {
      const path = `${group.vendor}/${file}`;
      const vendored = await Bun.file(path).text();
      local.set(file, vendored);
      const vendoredSha = await gitBlobSha(vendored);
      const recordedSha = previous.files[file];

      if (vendoredSha !== recordedSha) {
        fail(
          `${path} has been edited locally. It is vendored from ${REPO} and must not be changed here.\n` +
            `Change it in ${REPO} instead, then run: bun run sync:vendor`,
        );
      }
      const upstreamSha = await gitBlobSha(upstream.get(file)!);
      if (upstreamSha !== recordedSha) {
        fail(
          `${REPO} ${group.dir}/${file} has moved on since this copy was taken.\n` +
            `  vendored ${recordedSha}\n  upstream ${upstreamSha}\n` +
            `Run: bun run sync:vendor`,
        );
      }
    }
    checkImports(group, local);
    await checkConstants(group);
    console.log(
      `In sync with ${REPO} ${group.dir} (${group.files.join(", ")}).`,
    );
    continue;
  }

  const next: SourceRecord = {
    repo: REPO,
    ref: REF,
    dir: group.dir,
    files: {},
  };
  let wroteAny = false;

  for (const file of group.files) {
    const path = `${group.vendor}/${file}`;
    const text = upstream.get(file)!;
    const blobSha = await gitBlobSha(text);
    next.files[file] = blobSha;

    // Compare what is on disk, not just what was recorded. A locally edited
    // file is the case `--check` tells you to run this to fix, so skipping the
    // write when the record happens to match upstream would leave it broken
    // with nothing to do.
    const onDisk = await Bun.file(path)
      .text()
      .then(gitBlobSha)
      .catch(() => null);

    if (previous?.files?.[file] === blobSha && onDisk === blobSha) {
      console.log(`${path} already at ${blobSha}, nothing to write.`);
      continue;
    }

    await Bun.write(path, text);
    wroteAny = true;
    console.log(
      `Wrote ${path} from ${REPO} ${group.dir}/${file}\n  ${previous?.files?.[file] ?? "(new)"} -> ${blobSha}`,
    );
  }

  if (wroteAny) {
    await Bun.write(record, `${JSON.stringify(next, null, 2)}\n`);
  }
  // Checked after writing too: a file list that has gone stale is worth
  // hearing about while syncing, not one CI run later.
  checkImports(group, upstream);
  await checkConstants(group);
}
