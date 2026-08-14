/**
 * The promotion job (issue #111): the side effects, and nothing else.
 *
 * The order the run happens in, what each step guarantees and what an
 * interrupted run leaves behind are all in `lib/assets/promote.ts`, which is
 * where they are tested. This file is the six things that module cannot do
 * itself: read an object over HTTP, put a file in a checkout, commit and push
 * it, ask the published site whether it is serving yet, and delete from Blob.
 *
 *   bun run promote:assets --assets-repo ../coilbox-assets --dry-run
 *   bun run promote:assets --assets-repo ../coilbox-assets --write
 *
 * A dry run reads Postgres and reports. It writes nothing, pushes nothing,
 * deletes nothing and does not even read the bytes, so it costs one query and
 * can be run against production to see whether staging is draining.
 *
 * ## Where this runs, and which secret is where
 *
 * `.github/workflows/promote.yml` in tomjn/coilbox-assets, on a daily
 * schedule. It runs there rather than in the hub because it has to commit to
 * the assets repo, and a workflow in that repo gets `GITHUB_TOKEN`, which is
 * minted per run, scoped to that one repository and expires when the run ends.
 * The alternative, running in the hub, means a deploy key or a personal access
 * token with write access to another repository, stored indefinitely.
 *
 * The cost is that the two credentials this needs have to be repository
 * secrets on the assets repo: `SUPABASE_SERVICE_ROLE_KEY`, which bypasses row
 * level security on the whole database, and `BLOB_READ_WRITE_TOKEN`, which can
 * write the staging store. Neither has anything to do with publishing images,
 * and they are the two most powerful credentials the project has, so they are
 * worth naming rather than adding quietly.
 *
 * ## Why it dispatches the Pages workflow
 *
 * A push made with `GITHUB_TOKEN` does not start another workflow run, which
 * would leave the push sitting there undeployed while this waited for it.
 * `workflow_dispatch` is one of the two documented exceptions to that rule, so
 * the deploy is asked for explicitly. Run by hand, without a token, the push
 * starts the deploy the ordinary way and the dispatch is skipped.
 */

export {}; // top level await needs this file to be a module

import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { createClient } from "@supabase/supabase-js";
import { deleteBlobAssets } from "@/lib/assets/blob";
import { staticTierUrl } from "@/lib/assets/cdn";
import { PROMOTION_BATCH, type PromotionPorts, runPromotion } from "@/lib/assets/promote";

/** How long to wait for a push to become a published site. Pages redeploys the
 *  whole tier on every push (#119), so this grows with the corpus rather than
 *  with the batch, and the ten minute deploy #110 worries about has to fit
 *  inside it with room to spare. */
const SERVE_TIMEOUT_MS = 25 * 60 * 1000;
const POLL_MS = 15_000;

/** Deleting is free and does not touch the monthly allowance, but a batch
 *  counts per blob against the per minute rate limit, so a large run is paced
 *  rather than sent in one call. */
const DELETE_CHUNK = 100;
const DELETE_PAUSE_MS = 2_000;

const args = process.argv.slice(2);

function option(name: string): string | undefined {
  const at = args.indexOf(`--${name}`);
  return at === -1 ? undefined : args[at + 1];
}

// `--dry-run` is the default and says so out loud, which the workflow needs:
// an empty string is falsy in a GitHub expression, so a conditional that
// passes no flag at all cannot be written safely.
if (args.includes("--write") && args.includes("--dry-run")) {
  console.error("Asked for both --write and --dry-run. Pick one.");
  process.exit(1);
}

const write = args.includes("--write");
const repo = option("assets-repo");
const limit = Number(option("limit") ?? PROMOTION_BATCH);

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

if (write && !process.env.BLOB_READ_WRITE_TOKEN) {
  console.error("Need BLOB_READ_WRITE_TOKEN set to delete anything out of the staging tier.");
  process.exit(1);
}

// Which database, before anything is read or written, for the reason
// `backfill-game-names.ts` gives: Bun loads whichever env file happens to win
// and the two look identical from the output alone.
console.log(`${write ? "Promoting against" : "Reading"} ${new URL(url).host}`);

const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

async function git(...command: string[]): Promise<string> {
  const run = Bun.spawn(["git", "-C", repo!, ...command], {
    stdout: "pipe",
    stderr: "pipe",
  });
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
 * The query string is a cache buster and nothing else. Pages ignores it for
 * routing, and without it a 404 asked for a second too early can be the answer
 * a CDN keeps giving for the rest of the run.
 */
async function servedNow(path: string): Promise<boolean> {
  const response = await fetch(`${staticTierUrl(path)}?at=${Date.now()}`, {
    method: "HEAD",
    cache: "no-store",
  });
  return response.ok;
}

/** Ask GitHub to deploy, because a push made with `GITHUB_TOKEN` will not. */
async function dispatchPages(): Promise<void> {
  const token = process.env.GITHUB_TOKEN;
  const slug = process.env.GITHUB_REPOSITORY;
  const ref = process.env.GITHUB_REF_NAME ?? "main";

  if (!token || !slug) {
    console.log("No GITHUB_TOKEN, so the push itself is what starts the deploy.");
    return;
  }

  const response = await fetch(
    `https://api.github.com/repos/${slug}/actions/workflows/pages.yml/dispatches`,
    {
      method: "POST",
      headers: {
        accept: "application/vnd.github+json",
        authorization: `Bearer ${token}`,
        "x-github-api-version": "2022-11-28",
      },
      body: JSON.stringify({ ref }),
    },
  );

  if (!response.ok) {
    throw new Error(
      `Could not ask ${slug} to publish: ${response.status} ${await response.text()}`,
    );
  }
}

const ports: PromotionPorts = {
  read: async (from) => {
    const response = await fetch(from, { cache: "no-store" });
    if (!response.ok) throw new Error(`${response.status} reading ${from}`);
    return new Uint8Array(await response.arrayBuffer());
  },

  held: async (path) => await Bun.file(join(repo!, path)).exists(),

  write: async (path, bytes) => {
    const target = join(repo!, path);
    await mkdir(dirname(target), { recursive: true });
    await writeFile(target, bytes);
  },

  publish: async (paths) => {
    await git("add", "--", ...paths);

    // Nothing staged means every object in the batch was already committed by
    // a run that died before moving the rows. There is nothing to push and
    // nothing to deploy, and the objects are already being served.
    const staged = await git("diff", "--cached", "--name-only");
    if (staged === "") {
      console.log("Already committed by an earlier run, so nothing to push.");
      return;
    }

    const count = staged.split("\n").length;
    await git(
      "-c",
      "user.name=github-actions[bot]",
      "-c",
      "user.email=41898282+github-actions[bot]@users.noreply.github.com",
      "commit",
      "--message",
      `Promote ${count} picture${count === 1 ? "" : "s"} out of staging`,
    );
    await git("push");
    await dispatchPages();
  },

  serving: async (paths) => {
    if (paths.length === 0) return [];

    // Pages swaps the whole site at once, so one path going live means the
    // deploy landed. Wait on one, then confirm every one of them rather than
    // inferring it.
    const deadline = Date.now() + SERVE_TIMEOUT_MS;
    while (!(await servedNow(paths[0])) && Date.now() < deadline) {
      await sleep(POLL_MS);
    }

    const live: string[] = [];
    for (const path of paths) {
      if (await servedNow(path)) live.push(path);
    }
    return live;
  },

  discard: async (paths) => {
    for (let at = 0; at < paths.length; at += DELETE_CHUNK) {
      if (at > 0) await sleep(DELETE_PAUSE_MS);
      await deleteBlobAssets(paths.slice(at, at + DELETE_CHUNK));
    }
  },

  say: (message) => console.log(message),
};

if (!write) {
  const { durablePath, fetchPendingDeletions, fetchPromotable } = await import(
    "@/lib/assets/promote"
  );

  const leftover = await fetchPendingDeletions(supabase);
  for (const row of leftover) {
    console.log(`would delete ${row.blob_path}, promoted already and still in the store`);
  }

  const due = await fetchPromotable(supabase, limit);
  for (const row of due) {
    console.log(`would promote ${row.id}: ${row.path} -> ${durablePath(row) ?? "(unstorable)"}`);
  }

  console.log(
    `${leftover.length} left over, ${due.length} due. Dry run only. Re-run with --write to apply.`,
  );
} else {
  const result = await runPromotion(supabase, ports, { limit });

  console.log(
    `${result.drained} drained, ${result.promoted} promoted, ${result.deleted} deleted, ${result.skipped} skipped.`,
  );

  // A skip is a row the run read and did not move, which is normal once
  // (something changed underneath it) and a fault if it keeps happening. It is
  // not a reason to fail the run: the rows that did move, moved.
  if (result.skipped > 0) console.log("Skipped rows are listed above with the reason.");
}
