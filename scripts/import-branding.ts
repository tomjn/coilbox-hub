/**
 * The branding import: coilbox's branding catalog straight to the game rows.
 *
 * Coilbox brands an installed game from `catalog.json` - a banner, a logo and a
 * display-name override per entry (see the catalog's own docs upstream). The hub
 * has held the columns to carry the same art since ownership (#229) and renders
 * it on a game page already, but nothing ever filled them in. This does: it
 * reads the vendored catalog (`lib/games/vendor/catalog.json`, byte identical to
 * coilbox main), matches entries to games with the app's own rule
 * (`lib/games/brandingMatch.ts`), downloads each entry's first picture that is
 * a PNG or WebP the hub can store, publishes it to the durable tier, and writes
 * the row.
 *
 *   bun run import:branding                            dry run, prints the plan
 *   bun run import:branding --write                    applies it
 *   bun run import:branding --catalog ./catalog.json    a fresher copy than the vendored one
 *
 * A dry run does everything but publish: it downloads and checks every picture
 * so the plan says what would really happen, and writes no file, commits
 * nothing and touches no row.
 *
 * ## What matching means here
 *
 * Upstream matches one installed game, whose real name it holds, against the
 * catalog; here it is one hub game per walk, and the walk stops at the first
 * entry that matches - the same "first wins" reading of the catalog's
 * most-specific-first order. What the hub knows is a shortname and whatever
 * display name it holds, which is less than an install knows: a regex written
 * against "Splinter Faction" cannot see `SF`, and no rule should pretend
 * otherwise.
 *
 * So the matcher runs on what there is, and the gap is bridged where only a
 * person can bridge it: `--map SF=splinter-faction` pins a game to an entry
 * outright. A pinned or matched game with no display name takes the entry's
 * title override, which is what upstream shows as the game's name anyway and
 * what makes the row readable afterwards. A mapping that names an unknown
 * shortname or entry ends the run rather than being skipped over.
 *
 * ## Why the durable tier rather than Blob
 *
 * An owner upload goes through Blob because it happens at runtime, where there
 * is no assets checkout. This script runs beside one, and seed-assets.ts
 * explains at length why these files go into it directly: fewer metered
 * operations, and the picture is being served the moment the row lands.
 *
 * ## The order, and what an interrupted run leaves
 *
 * Files first, rows last, for the reason seed-assets.ts gives: a file nothing
 * points at costs nothing, while a row pointing at an unpublished file is a 404
 * in front of somebody. Both halves are safe to run again - the paths are
 * deterministic (`games/<shortname>/<kind>.<ext>`, the same shape an owner
 * upload uses) and a row already saying what this run would write is left
 * alone.
 */

export {}; // top level await needs this file to be a module

import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { createClient } from "@supabase/supabase-js";
import { staticTierUrl } from "@/lib/assets/cdn";
import { readImageHeader } from "@/lib/assets/imageHeader";
import {
  compileBrandingEntries,
  resolveBrandingEntry,
  type BrandingEntryLike,
} from "@/lib/games/brandingMatch";

/** How long to wait for a push to become a published site, and how long to
 *  leave a path that answered 404 before asking again. The same numbers and the
 *  same reasons as `seed-assets.ts`: Pages redeploys the whole tier per push,
 *  and one negative answer is not evidence a file is missing. */
const SERVE_TIMEOUT_MS = 25 * 60 * 1000;
const POLL_MS = 15_000;
const RETRY_MS = 2_000;

/** Pushes that lose a race with the daily promotion job before giving up. */
const PUSH_ATTEMPTS = 3;

/** The same cap an owner upload is held to (`app/games/actions.ts`), and then
 *  some. These are maintainer-curated pictures going into a git history rather
 *  than runtime uploads onto a metered store, so the ceiling sits higher - a
 *  3.9 MB banner is ordinary art by catalog standards - but it exists all the
 *  same: a URL list can point anywhere, and nothing here should pull down an
 *  unbounded file because a catalog entry said so. */
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

const args = process.argv.slice(2);

function option(name: string): string | undefined {
  const at = args.indexOf(`--${name}`);
  return at === -1 ? undefined : args[at + 1];
}

function options(name: string): string[] {
  return args
    .flatMap((arg, at) => (arg === `--${name}` ? [args[at + 1]] : []))
    .filter((value): value is string => Boolean(value));
}

if (args.includes("--write") && args.includes("--dry-run")) {
  console.error("Asked for both --write and --dry-run. Pick one.");
  process.exit(1);
}

const write = args.includes("--write");
const repo = option("assets-repo");
const catalogArg = option("catalog");

if (!repo) {
  console.error(
    "Need --assets-repo <path>, a checkout of tomjn/coilbox-assets on the branch it publishes.",
  );
  process.exit(1);
}

const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
if (!url || !key) {
  console.error(
    "Need NEXT_PUBLIC_SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY set. " +
      "See .env.development.local for the local stack.",
  );
  process.exit(1);
}

console.log(`${write ? "Importing" : "Reading"} ${new URL(url).host}`);

const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

// ---------------------------------------------------------------------------
// The catalog, and the games it might brand.
// ---------------------------------------------------------------------------

interface CatalogEntry {
  id: string;
  match: { regex?: string; names?: string[] };
  title?: string;
  banner?: string[];
  logo?: string[];
}

function readCatalog(source: string): CatalogEntry[] {
  const parsed = JSON.parse(source) as { entries?: unknown };
  if (!Array.isArray(parsed.entries)) {
    throw new Error("The catalog has no entries array.");
  }
  return parsed.entries as CatalogEntry[];
}

const catalogSource = catalogArg
  ? await readFile(catalogArg, "utf8")
  : await readFile(join(import.meta.dir, "../lib/games/vendor/catalog.json"), "utf8");
const entries = readCatalog(catalogSource);
const compiled = compileBrandingEntries(entries);
console.log(`${compiled.length} entries in the catalog.`);
// ---------------------------------------------------------------------------
// Which entry brands which game.
//
// The matcher runs on what the hub knows. A --map pin replaces the walk for the
// games a person has vouched for, and is checked against both sides of itself
// before anything else happens, because a typo there would otherwise surface as
// a quietly unbranded game.
// ---------------------------------------------------------------------------

interface GameRow {
  id: string;
  shortname: string;
  display_name: string | null;
  logo_path: string | null;
  logo_hash: string | null;
  banner_path: string | null;
  banner_hash: string | null;
}

const { data: held, error: readError } = await supabase
  .from("game")
  .select("id,shortname,display_name,logo_path,logo_hash,banner_path,banner_hash");
if (readError) throw new Error(`Could not read the games: ${readError.message}`);
const games = (held ?? []) as GameRow[];

const pinned = new Map<string, string>();
for (const pair of options("map")) {
  const [shortname, entryId] = pair.split("=", 2);
  if (!shortname || !entryId) {
    console.error(`--map ${pair} is not shortname=entry-id.`);
    process.exit(1);
  }
  if (!games.some((row) => row.shortname === shortname)) {
    console.error(`--map ${pair}: no game "${shortname}" in this database.`);
    process.exit(1);
  }
  if (!entries.some((entry) => entry.id === entryId)) {
    console.error(`--map ${pair}: no catalog entry "${entryId}".`);
    process.exit(1);
  }
  pinned.set(shortname, entryId);
}

const renames: { row: GameRow; title: string; via: string }[] = [];
const branded = new Map<string, BrandingEntryLike>();
for (const row of games) {
  const pinnedId = pinned.get(row.shortname);
  if (pinnedId) {
    branded.set(row.id, compiled.find((entry) => entry.id === pinnedId)!);
  } else {
    const match = resolveBrandingEntry(compiled, {
      shortname: row.shortname,
      displayName: row.display_name,
    });
    if (!match) continue;
    branded.set(row.id, match);
  }

  // The winner's title override fills a blank display name, which is what
  // upstream shows as the game's name and what makes the row readable after.
  const winner = branded.get(row.id) as BrandingEntryLike & { title?: string };
  if (!row.display_name && winner.title) {
    renames.push({ row, title: winner.title, via: winner.id });
  }
}

// ---------------------------------------------------------------------------
// The pictures: first candidate that fetches, fits the cap and sniffs as a PNG
// or WebP. Anything else is reported and the next candidate tried, because the
// lists are ordered fallbacks by contract and a .jpg banner is an ordinary
// miss, not a fault.
// ---------------------------------------------------------------------------

async function pick(candidates: string[]): Promise<
  { bytes: Uint8Array; ext: "png" | "webp"; url: string } | { reason: string }
> {
  let said: string | null = null;
  for (const candidate of candidates) {
    let response: Response;
    try {
      response = await fetch(candidate, { redirect: "follow" });
    } catch (failure) {
      said = `${candidate}: ${(failure as Error).message}`;
      continue;
    }
    if (!response.ok) {
      said = `${candidate}: HTTP ${response.status}`;
      continue;
    }
    const buffer = await response.arrayBuffer();
    if (buffer.byteLength > MAX_IMAGE_BYTES) {
      said = `${candidate}: ${buffer.byteLength} bytes, over the ${MAX_IMAGE_BYTES} cap`;
      continue;
    }
    const bytes = new Uint8Array(buffer);
    // The whole file, not a header window: an extended WebP may put its image
    // chunk after metadata that runs past 4 KB, and these bytes are already in
    // memory. The parse is still shallow - it walks chunk headers, never
    // decodes.
    const header = readImageHeader(bytes);
    if (!header) {
      said = `${candidate}: not a PNG or WebP the hub can measure`;
      continue;
    }
    return { bytes, ext: header.mime === "image/png" ? "png" : "webp", url: candidate };
  }
  return { reason: said ?? "no candidates at all" };
}

interface Wanted {
  row: GameRow;
  kind: "logo" | "banner";
  path: string;
  hash: string;
  bytes: Uint8Array;
  from: string;
}

const wanted: Wanted[] = [];
const skipped: string[] = [];

for (const row of games) {
  const entry = branded.get(row.id) as
    | (BrandingEntryLike & { banner?: string[]; logo?: string[] })
    | undefined;
  if (!entry) continue;

  // A re-run finds its own work already done: same path shape, and the stored
  // hash over the bytes we would download again. Skipping the download would
  // need the bytes anyway to prove they are unchanged, so compare after.
  for (const kind of ["banner", "logo"] as const) {
    const candidates = entry[kind];
    if (!candidates || candidates.length === 0) continue;

    const column = kind === "logo" ? "logo_hash" : "banner_hash";
    const picked = await pick(candidates);
    if ("reason" in picked) {
      skipped.push(`${row.shortname} ${kind}: ${picked.reason}`);
      continue;
    }

    const hash = createHash("sha256").update(picked.bytes).digest("hex");
    if (row[column] === hash) continue;

    wanted.push({
      row,
      kind,
      path: `games/${row.shortname}/${kind}.${picked.ext}`,
      hash,
      bytes: picked.bytes,
      from: picked.url,
    });
  }
}

// ---------------------------------------------------------------------------
// The report, which is all a dry run is.
// ---------------------------------------------------------------------------

for (const rename of renames) {
  console.log(`name ${rename.row.shortname}: "${rename.title}" (from "${rename.via}")`);
}
for (const [id] of branded) {
  const row = games.find((g) => g.id === id)!;
  console.log(`brand ${row.shortname} <- "${branded.get(id)!.id}"`);
}
for (const item of wanted) {
  console.log(
    `write ${item.path} (${item.bytes.byteLength} bytes, from ${item.from}), ` +
      `set ${item.kind}_hash on ${item.row.shortname}`,
  );
}
for (const reason of skipped) console.log(`skip ${reason}`);

const touched = new Set<string>([
  ...renames.map((r) => r.row.id),
  ...wanted.map((w) => w.row.id),
]);
console.log(
  `${games.length} games: ${renames.length} named, ${wanted.length} pictures across ` +
    `${new Set(wanted.map((w) => w.row.id)).size} games, ${skipped.length} misses, ` +
    `${games.length - touched.size} with nothing to do.`,
);

if (!write) {
  console.log("Dry run only. Re-run with --write to apply.");
  process.exit(0);
}

// ---------------------------------------------------------------------------
// The files, then the rows.
// ---------------------------------------------------------------------------

async function git(...command: string[]): Promise<string> {
  const run = Bun.spawn(["git", "-C", repo!, ...command], { stdout: "pipe", stderr: "pipe" });
  const [out, err] = await Promise.all([
    new Response(run.stdout).text(),
    new Response(run.stderr).text(),
  ]);
  if ((await run.exited) !== 0) {
    throw new Error(`git ${command.join(" ")} failed: ${err.trim() || out.trim()}`);
  }
  return out.trim();
}

if ((await git("diff", "--cached", "--name-only")) !== "") {
  console.error(`${repo} has staged changes. Commit or reset them, then run this again.`);
  process.exit(1);
}

let copied = 0;
for (const item of wanted) {
  const target = join(repo!, item.path);
  const existing = await readFile(target).catch(() => null);
  if (existing && createHash("sha256").update(existing).digest("hex") === item.hash) {
    continue;
  }
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, item.bytes);
  copied += 1;
}

if (copied > 0) {
  await git("add", "--", ...wanted.map((item) => item.path));
  await git("commit", "--message", `Brand ${new Set(wanted.map((item) => item.row.shortname)).size} games from the branding catalog`);
  for (let attempt = 1; ; attempt++) {
    try {
      await git("push");
      break;
    } catch (failure) {
      if (attempt === PUSH_ATTEMPTS) throw failure;
      console.log(`Push ${attempt} was rejected, so rebasing onto what is there and retrying.`);
      await git("pull", "--rebase");
    }
  }

  async function servedNow(path: string, attempts = 3): Promise<boolean> {
    for (let attempt = 1; ; attempt++) {
      const response = await fetch(`${staticTierUrl(path)}?at=${Date.now()}`, {
        method: "HEAD",
        cache: "no-store",
      });
      if (response.ok) return true;
      if (attempt === attempts) return false;
      await sleep(RETRY_MS);
    }
  }

  const deadline = Date.now() + SERVE_TIMEOUT_MS;
  while (!(await servedNow(wanted[0].path, 1)) && Date.now() < deadline) {
    await sleep(POLL_MS);
  }
  for (const item of wanted) {
    if (!(await servedNow(item.path))) {
      throw new Error(`${item.path} never came up on the durable tier. Rows not written.`);
    }
  }
} else {
  console.log("Every picture is already published.");
}

let renamedRows = 0;
for (const rename of renames) {
  const { error } = await supabase
    .from("game")
    .update({ display_name: rename.title })
    .eq("id", rename.row.id);
  if (error) {
    console.error(`Could not name ${rename.row.shortname}: ${error.message}`);
    continue;
  }
  renamedRows += 1;
}

let writtenRows = 0;
for (const item of wanted) {
  const patch =
    item.kind === "logo"
      ? { logo_path: item.path, logo_hash: item.hash }
      : { banner_path: item.path, banner_hash: item.hash };
  const { error } = await supabase.from("game").update(patch).eq("id", item.row.id);
  if (error) {
    console.error(`Could not brand ${item.row.shortname} ${item.kind}: ${error.message}`);
    continue;
  }
  writtenRows += 1;
}

console.log(`${renamedRows} names written, ${writtenRows} pictures written.`);

// Pages read games through a cache tagged `games`, which this script cannot
// reach from outside the app. It expires on its own; a deploy or any owner edit
// clears it sooner.
