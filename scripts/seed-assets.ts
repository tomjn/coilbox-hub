/**
 * The seed (issue #110): the maintainer's own collection straight to the
 * durable tier, and the side effects only.
 *
 * What an export means, which rows it makes and what the hub will not take are
 * all in `lib/assets/seed.ts`, and how a corpus is spread over several deploys
 * is in `lib/assets/seedBatch.ts`. This file is the parts neither can do: read
 * files, copy them into a checkout, commit and push, ask the published site
 * whether it is serving, and write the rows.
 *
 *   bun run seed:assets --seed <dir> --assets-repo ../coilbox-assets
 *   bun run seed:assets --seed <dir> --assets-repo ../coilbox-assets --write
 *   bun run seed:assets --seed <dir> --assets-repo ../coilbox-assets --skip-variant overlay:height
 *
 * A dry run reads the export and the database and reports. It copies nothing,
 * commits nothing and writes no row, so it can be run against production.
 *
 * ## Why this does not go through Blob
 *
 * The staging store allows 2,000 advanced operations a month and a `put()` is
 * one each, so seeding three thousand pictures through it is not merely untidy,
 * it is impossible, and going over removes Blob access for 30 days with no way
 * to pay through it. These files are already on disk beside a git checkout of
 * the durable tier, so they go in it.
 *
 * ## The order, and what an interrupted run leaves
 *
 * Files first, rows last. A file nothing points at is invisible and costs
 * nothing, and finishing the run picks it up. A row pointing at a file that is
 * not published yet is a 404 in front of somebody, so it is the order that can
 * only fail the harmless way.
 *
 * Both halves are safe to run again. The paths are content addressed, so a file
 * already committed stages nothing, and the rows are matched on identity and
 * left alone when they already say what this run would write.
 *
 * ## Every byte is checked before anything is committed
 *
 * A git history cannot be rewritten, so the whole export is held to its own
 * manifest and to the hub's caps before the first file is copied. That reads the
 * corpus twice, which costs seconds and buys the only chance to refuse.
 */

export {}; // top level await needs this file to be a module

import { createHash } from "node:crypto";
import { copyFile, mkdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { createClient, type SupabaseClient } from "@supabase/supabase-js";
import { staticTierUrl } from "@/lib/assets/cdn";
import {
  checkSeedBytes,
  planSeed,
  readSeedManifest,
  type SeedEntry,
  seedIdentityKey,
} from "@/lib/assets/seed";
import { publishSeedBatches, type SeedPorts } from "@/lib/assets/seedBatch";

/** How long to wait for a push to become a published site. Pages redeploys the
 *  whole tier on every push, so this grows with the corpus rather than with the
 *  batch, the same as `./promote-assets.ts`. */
const SERVE_TIMEOUT_MS = 25 * 60 * 1000;
const POLL_MS = 15_000;

/** How long to leave a path that answered 404 before asking it again. */
const RETRY_MS = 2_000;

/** How many paths go on one `git add`. Well under any argument limit, and a
 *  batch of this export runs to sixteen hundred files. */
const ADD_CHUNK = 400;

/** How many rows go in one insert. PostgREST takes far more, but a failure
 *  reports one statement's worth and a smaller number is a smaller thing to
 *  read. */
const INSERT_CHUNK = 500;

/** How many rows one read of the table takes. PostgREST's own default ceiling. */
const PAGE_SIZE = 1000;

/** Pushes that lose a race with the daily promotion job before giving up. It
 *  commits to the same branch of the same repository, so a rejected push is an
 *  ordinary event rather than a fault, and both sides only ever add files. */
const PUSH_ATTEMPTS = 3;

const args = process.argv.slice(2);

function option(name: string): string | undefined {
  const at = args.indexOf(`--${name}`);
  return at === -1 ? undefined : args[at + 1];
}

function options(name: string): string[] {
  return args.flatMap((arg, at) => (arg === `--${name}` ? [args[at + 1]] : [])).filter(Boolean);
}

if (args.includes("--write") && args.includes("--dry-run")) {
  console.error("Asked for both --write and --dry-run. Pick one.");
  process.exit(1);
}

const write = args.includes("--write");
const seedDir = option("seed");
const repo = option("assets-repo");
const skipVariants = options("skip-variant");

if (!seedDir) {
  console.error("Need --seed <dir>, a coilbox seed export with a manifest.json in it.");
  process.exit(1);
}

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

// Which database, before anything is read or written, for the reason
// `backfill-game-names.ts` gives: Bun loads whichever env file happens to win
// and the two look identical from the output alone.
console.log(`${write ? "Seeding" : "Reading"} ${new URL(url).host}`);

const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

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

/**
 * Whether the published site answers for a path.
 *
 * The query string is a cache buster: without it a 404 asked for a second too
 * early is the answer a CDN keeps giving for the rest of the run.
 *
 * `attempts` is why this is not `./promote-assets.ts`'s copy. Confirming a
 * batch asks for every path in it one after another, and over hundreds of
 * requests Pages answers 404 for a path that is there, twice in this seed's own
 * runs. Both times the same path answered 200 on the next request and forty
 * after it. So a single negative is not evidence a file is missing, and taking
 * it as one ends a run that has nothing wrong with it. Polling for a deploy
 * passes 1, because there a negative is the expected answer and waiting is what
 * the loop already does.
 */
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

// ---------------------------------------------------------------------------
// What the export says, and whether the hub can take it.
// ---------------------------------------------------------------------------

const manifest = readSeedManifest(await readFile(join(seedDir, "manifest.json"), "utf8"));
const plan = planSeed(manifest, { skipVariants });

console.log(
  `${manifest.assets.length} assets in the export: ${plan.entries.length} to seed, ` +
    `${plan.heldBack.length} held back, ${plan.refused.length} the hub cannot store.`,
);

for (const variant of skipVariants) {
  const held = plan.heldBack.filter((asset) => asset.variant === variant);
  const bytes = held.reduce((total, asset) => total + asset.bytes, 0);
  console.log(`Holding back ${held.length} "${variant}", ${bytes} bytes, as asked.`);
}

// A refusal ends the run rather than being stepped over. Every one of them is
// something a check constraint or a cap would refuse, and finding that out
// after the files are in a git history is the one order that cannot be undone.
if (plan.refused.length > 0) {
  for (const refusal of plan.refused) {
    const named =
      refusal.asset.kind === "unit"
        ? `${refusal.asset.game}/${refusal.asset.unitName}`
        : refusal.asset.mapName;
    console.error(`refused ${named} ${refusal.asset.variant}: ${refusal.reason}`);
  }
  console.error(`${plan.refused.length} refused. Nothing was published. Fix the export.`);
  process.exit(1);
}

if (plan.entries.length === 0) {
  console.log("Nothing to seed.");
  process.exit(0);
}

// ---------------------------------------------------------------------------
// The bytes, held to the manifest and to their class before anything moves.
// ---------------------------------------------------------------------------

console.log(`Checking ${plan.entries.length} files against the manifest and the caps.`);

const faults: string[] = [];
let checkedBytes = 0;

for (const entry of plan.entries) {
  const bytes = new Uint8Array(await readFile(join(seedDir, entry.asset.file)));
  const hash = createHash("sha256").update(bytes).digest("hex");
  const checked = checkSeedBytes(entry.asset, bytes, hash);

  if (!checked.ok) faults.push(`${entry.asset.file}: ${checked.error}`);
  checkedBytes += bytes.byteLength;
}

if (faults.length > 0) {
  for (const fault of faults) console.error(fault);
  console.error(`${faults.length} files disagree with the manifest. Nothing was published.`);
  process.exit(1);
}

const files = new Set(plan.entries.map((entry) => entry.object.to)).size;
console.log(`${plan.entries.length} assets over ${files} files, ${checkedBytes} bytes, all sound.`);

// ---------------------------------------------------------------------------
// What the hub already holds.
// ---------------------------------------------------------------------------

interface HeldRow {
  id: string;
  hash: string;
  source_hash: string;
  path: string;
  tier: string;
  moderation: string;
}

/** Every row already in the table, by identity key. The whole table rather than
 *  the export's keys, because PostgREST has no way to ask about three thousand
 *  composite keys in one request and the table is the smaller thing. */
async function heldRows(client: SupabaseClient): Promise<Map<string, HeldRow>> {
  const held = new Map<string, HeldRow>();

  for (let from = 0; ; from += PAGE_SIZE) {
    const { data, error } = await client
      .from("asset")
      .select("id,game,unit_name,map_name,variant,hash,source_hash,path,tier,moderation")
      .order("id", { ascending: true })
      .range(from, from + PAGE_SIZE - 1);

    if (error) throw new Error(`Could not read what the hub already holds: ${error.message}`);

    const page = (data ?? []) as (HeldRow & {
      game: string | null;
      unit_name: string | null;
      map_name: string | null;
      variant: string;
    })[];

    for (const row of page) {
      const identity = row.unit_name
        ? ({
            keyedOn: "unit",
            game: row.game ?? "",
            unitName: row.unit_name,
            variant: row.variant,
          } as const)
        : ({ keyedOn: "map", mapName: row.map_name ?? "", variant: row.variant } as const);

      held.set(seedIdentityKey(identity), row);
    }

    if (page.length < PAGE_SIZE) return held;
  }
}

const held = await heldRows(supabase);

const inserting: SeedEntry[] = [];
const updating: { entry: SeedEntry; row: HeldRow }[] = [];
const rejected: SeedEntry[] = [];
let unchanged = 0;

for (const entry of plan.entries) {
  const already = held.get(entry.key);

  if (!already) {
    inserting.push(entry);
    continue;
  }

  // A moderator has already looked at this identity and said no. A seed is not
  // a way around that, whoever the pictures belong to, and a safety rejection
  // is final in the database anyway, so this would be an error rather than a
  // change. Reported, because a seed silently declining to write a row it was
  // handed is worse than the refusal.
  if (already.moderation === "rejected") {
    rejected.push(entry);
    continue;
  }

  const same =
    already.hash === entry.row.hash &&
    already.source_hash === entry.row.source_hash &&
    already.path === entry.row.path &&
    already.tier === "static" &&
    already.moderation === "approved";

  if (same) unchanged++;
  else updating.push({ entry, row: already });
}

console.log(
  `${held.size} rows in the table now. ${inserting.length} to insert, ` +
    `${updating.length} to replace, ${unchanged} already right.`,
);

for (const entry of rejected) {
  const named =
    entry.asset.kind === "unit"
      ? `${entry.asset.game}/${entry.asset.unitName}`
      : entry.asset.mapName;
  console.log(`leaving ${named} ${entry.asset.variant} alone: a moderator rejected that identity.`);
}

// The files follow the rows they would serve, so bytes for a rejected identity
// are not committed either. One whose bytes another kept asset also points at
// is published anyway, which is right: that file is a picture somebody wants.
const rejectedKeys = new Set(rejected.map((entry) => entry.key));
const seeding = plan.entries.filter((entry) => !rejectedKeys.has(entry.key));

if (!write) {
  const batches = new Set(seeding.map((entry) => entry.object.batch));
  console.log(`${batches.size} batches, so ${batches.size} pushes and ${batches.size} deploys.`);

  const wanted = new Set(seeding.map((entry) => entry.object.to));
  let there = 0;
  for (const path of wanted) {
    if (await Bun.file(join(repo, path)).exists()) there++;
  }
  console.log(`${there} of ${wanted.size} files are already in the checkout.`);

  console.log("Dry run only. Re-run with --write to apply.");
  process.exit(0);
}

// ---------------------------------------------------------------------------
// The files.
// ---------------------------------------------------------------------------

// A checkout with something already staged would have it swept into this run's
// commit, which puts somebody's unrelated work in the durable tier's history
// under a message about pictures.
if ((await git("diff", "--cached", "--name-only")) !== "") {
  console.error(`${repo} has staged changes. Commit or reset them, then run this again.`);
  process.exit(1);
}

const ports: SeedPorts = {
  held: async (to) => await Bun.file(join(repo, to)).exists(),

  copy: async (from, to) => {
    const target = join(repo, to);
    await mkdir(dirname(target), { recursive: true });
    await copyFile(join(seedDir, from), target);
  },

  publish: async (paths) => {
    for (let at = 0; at < paths.length; at += ADD_CHUNK) {
      await git("add", "--", ...paths.slice(at, at + ADD_CHUNK));
    }

    // Nothing staged means every file in the batch was already committed by an
    // earlier run. There is nothing to push and nothing to deploy.
    const staged = await git("diff", "--cached", "--name-only");
    if (staged === "") return false;

    const count = staged.split("\n").length;
    await git("commit", "--message", `Seed ${count} pictures into the durable tier`);

    // The promotion job pushes to this branch too, on a daily schedule, so a
    // rejected push is an ordinary event. Both sides only add files, so
    // rebasing onto whatever landed is the whole of the fix.
    for (let attempt = 1; ; attempt++) {
      try {
        await git("push");
        return true;
      } catch (failure) {
        if (attempt === PUSH_ATTEMPTS) throw failure;
        console.log(`Push ${attempt} was rejected, so rebasing onto what is there and retrying.`);
        await git("pull", "--rebase");
      }
    }
  },

  serving: async (paths) => {
    if (paths.length === 0) return [];

    // Pages swaps the whole site at once, so one path going live means the
    // deploy landed. Wait on one, then confirm every one of them.
    const deadline = Date.now() + SERVE_TIMEOUT_MS;
    while (!(await servedNow(paths[0], 1)) && Date.now() < deadline) {
      await sleep(POLL_MS);
    }

    const live: string[] = [];
    for (const path of paths) {
      if (await servedNow(path)) live.push(path);
    }
    return live;
  },

  say: (message) => console.log(message),
};

const published = await publishSeedBatches(
  seeding.map((entry) => entry.object),
  ports,
);

console.log(
  `${published.published} batches published, ${published.alreadyPublished} already there, ` +
    `${published.copied} files copied.`,
);

// ---------------------------------------------------------------------------
// The rows, once the pictures they name are being served.
// ---------------------------------------------------------------------------

let inserted = 0;
for (let at = 0; at < inserting.length; at += INSERT_CHUNK) {
  const chunk = inserting.slice(at, at + INSERT_CHUNK);
  const { error } = await supabase.from("asset").insert(chunk.map((entry) => entry.row));

  if (error) throw new Error(`Could not write rows ${at} to ${at + chunk.length}: ${error.message}`);
  inserted += chunk.length;
  console.log(`Wrote ${inserted} of ${inserting.length} rows.`);
}

let replaced = 0;
for (const { entry, row } of updating) {
  const { error } = await supabase
    .from("asset")
    .update({ ...entry.row, seen_at: new Date().toISOString() })
    .eq("id", row.id);

  if (error) {
    console.error(`Could not replace ${row.id} for ${entry.asset.variant}: ${error.message}`);
    continue;
  }
  replaced++;
}

console.log(`${inserted} rows written, ${replaced} replaced, ${unchanged} left alone.`);
