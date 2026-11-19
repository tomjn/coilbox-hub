/**
 * The promotion job (issue #111): the side effects, and nothing else.
 *
 * The order the run happens in, what each step guarantees and what an
 * interrupted run leaves behind are all in `lib/assets/promote.ts`, which is
 * where they are tested. This file is the things that module cannot do
 * itself: read a staged object from the hub, put a file in a checkout, commit
 * and push it, ask the published site whether it is serving yet, and ask the
 * hub to delete the staged copy.
 *
 *   bun run promote:assets --assets-repo ../coilbox-assets --dry-run
 *   bun run promote:assets --assets-repo ../coilbox-assets --write
 *   bun run promote:assets --withdrawn <asset-id>
 *
 * A dry run asks the hub and reports. It writes nothing, pushes nothing,
 * deletes nothing and does not even read the bytes, so it costs a few requests
 * and can be run against production to see whether staging is draining.
 *
 * ## The takedown queue, which this reports and never acts on
 *
 * Every run, dry or not, starts by saying what was rejected on safety grounds
 * after it had already been promoted (#153). Those files are in a public git
 * history and nothing here removes them: a commit against the assets repo and a
 * `git filter-repo` force push that breaks every clone are not things to hand a
 * daily job and a stored credential, and doing the first without the second
 * reads as finished while the blob is still fetchable at its old commit.
 *
 * So a person does both, and `--withdrawn <asset-id>` is how they say so. The
 * reasoning is in `lib/assets/withdraw.ts`, including why promotion is not
 * gated on the queue being empty.
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
 * The cost is a repository secret on the assets repo: `PROMOTION_TOKEN`, with
 * `HUB_URL` beside it (#616). It opens only the hub's `/api/v1/promotion`
 * routes, which read approved pictures and staged game art, record promotions
 * and delete staged objects nothing claims. It replaced
 * `SUPABASE_SERVICE_ROLE_KEY`, which bypassed row level security on the whole
 * database, once pictures and games moved to the hub's MariaDB.
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

import { mkdir, rm, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { staticTierUrl } from "@/lib/assets/cdn";
import { servingAfterDeploy } from "@/lib/assets/deployWait";
import { promotionStoreFromEnv } from "@/lib/assets/jobStore";
import { PROMOTION_BATCH, type PromotionPorts, runPromotion } from "@/lib/assets/promote";
import { runGameImagePromotion } from "@/lib/assets/promoteGameImages";
import {
  fetchOutstandingWithdrawals,
  recordWithdrawn,
  withdrawalReport,
} from "@/lib/assets/withdraw";

/** How long to wait for a push to become a published site. Pages redeploys the
 *  whole tier on every push (#119), so this grows with the corpus rather than
 *  with the batch, and the ten minute deploy #110 worries about has to fit
 *  inside it with room to spare. */
const SERVE_TIMEOUT_MS = 25 * 60 * 1000;
const POLL_MS = 15_000;

/** How many paths one deletion call takes at once. */
const DELETE_CHUNK = 100;

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

// Settling a takedown is its own errand. It touches no checkout, promotes
// nothing and is somebody reporting what they did to a git repository by hand.
const withdrawn = option("withdrawn");

if (withdrawn && write) {
  console.error("Asked to promote and to settle a takedown in the same run. Pick one.");
  process.exit(1);
}

if (!repo && !withdrawn) {
  console.error(
    "Need --assets-repo <path>, a checkout of tomjn/coilbox-assets on the branch it publishes.",
  );
  process.exit(1);
}

const hub = promotionStoreFromEnv();
if ("error" in hub) {
  console.error(hub.error);
  process.exit(1);
}
const { store } = hub;

// Which hub, before anything is read or written, for the reason
// `backfill-game-names.ts` gives: Bun loads whichever env file happens to win
// and a local hub and production look identical from the output alone.
console.log(`${write ? "Promoting against" : "Reading"} ${hub.host}`);

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

/** The paths this run has committed, which are the ones a deploy has to carry
 *  before the published site can answer for them. */
const committed = new Set<string>();

const ports: PromotionPorts = {
  // Through the job's API rather than the public staged picture URL, which
  // only serves approved pictures. A missing object is a 404, so the game
  // picture pass can tell absent from refused.
  readStaged: (path) => store.readStaged(path),

  held: async (path) => await Bun.file(join(repo!, path)).exists(),

  write: async (path, bytes) => {
    const target = join(repo!, path);
    await mkdir(dirname(target), { recursive: true });
    await writeFile(target, bytes);
  },

  // Off the disk and out of the index. The file may be one this run wrote and
  // never committed, which `git rm` alone would leave on the disk.
  remove: async (path) => {
    await rm(join(repo!, path), { force: true });
    await git("rm", "--cached", "--ignore-unmatch", "--quiet", "--", path);
  },

  publish: async (paths) => {
    // A run that only removes files has nothing to add. Removals are already
    // staged by `remove`.
    if (paths.length > 0) await git("add", "--", ...paths);

    // Nothing staged means every object in the batch was already committed by
    // an earlier run. There is nothing to push, but that run's deploy may have
    // failed, and no later push will ask for another. So if any of them is not
    // being served, ask for the deploy here and wait for it like a fresh push.
    // Promotion runs once a day with one batch, so this costs at most one site
    // rebuild a day, unlike the seed (see seedBatch.ts).
    const staged = await git("diff", "--cached", "--name-status");
    if (staged === "") {
      const missing: string[] = [];
      for (const path of paths) {
        if (!(await servedNow(path))) missing.push(path);
      }
      if (missing.length === 0) {
        console.log("Already committed and served, so nothing to push.");
        return;
      }
      console.log(
        `Already committed, but ${missing.length} of ${paths.length} are not served. Asking Pages to deploy again.`,
      );
      for (const path of missing) committed.add(path);
      await dispatchPages();
      return;
    }

    const lines = staged.split("\n");
    for (const line of lines) {
      if (!line.startsWith("D")) committed.add(line.slice(line.indexOf("\t") + 1));
    }
    const removed = lines.filter((line) => line.startsWith("D")).length;
    const count = lines.length - removed;
    const pictures = (n: number) => `${n} picture${n === 1 ? "" : "s"}`;
    const message =
      removed === 0
        ? `Promote ${pictures(count)} out of staging`
        : count === 0
          ? `Remove ${pictures(removed)} the hub no longer names`
          : `Promote ${pictures(count)} out of staging and remove ${removed} the hub no longer names`;
    await git(
      "-c",
      "user.name=github-actions[bot]",
      "-c",
      "user.email=41898282+github-actions[bot]@users.noreply.github.com",
      "commit",
      "--message",
      message,
    );
    await git("push");
    await dispatchPages();
  },

  serving: async (paths) =>
    await servingAfterDeploy(paths, committed, {
      landed: servedNow,
      served: servedNow,
      sleep,
      now: Date.now,
      timeoutMs: SERVE_TIMEOUT_MS,
      pollMs: POLL_MS,
    }),

  discardStaged: async (paths) => {
    for (let at = 0; at < paths.length; at += DELETE_CHUNK) {
      await store.discard(paths.slice(at, at + DELETE_CHUNK));
    }
  },

  say: (message) => console.log(message),
};

// First, and on every run whatever it was asked to do. A takedown that only
// shows up when somebody thinks to look for it is a takedown nobody sees.
for (const line of withdrawalReport(await fetchOutstandingWithdrawals(store))) {
  console.log(line);
}

if (withdrawn) {
  const settled = await recordWithdrawn(store, [withdrawn]);
  console.log(
    settled === 1
      ? `Recorded ${withdrawn} as removed from the durable tier.`
      : `Nothing outstanding for ${withdrawn}. It was never queued, or it is already settled.`,
  );
} else if (!write) {
  const { durablePath, fetchPendingDeletions, fetchPromotable } = await import(
    "@/lib/assets/promote"
  );
  const { fetchStagedGameImages, strayGameImages } = await import("@/lib/assets/promoteGameImages");

  const leftover = await fetchPendingDeletions(store);
  for (const row of leftover) {
    console.log(`would delete ${row.blob_path}, promoted already and still staged`);
  }

  const due = await fetchPromotable(store, limit);
  for (const row of due) {
    console.log(
      `would promote ${row.id}: ${row.tier} ${row.path} -> ${durablePath(row) ?? "(unstorable)"}`,
    );
  }

  const staged = await fetchStagedGameImages(store);
  let waiting = 0;
  const seen = new Set<string>();
  for (const image of staged) {
    if (seen.has(image.path)) continue;
    seen.add(image.path);
    console.log(`would promote game ${image.kind} ${image.path} from staging`);
    waiting++;
  }

  // Removed art, and art at an old extension, still in the checkout (#360).
  const stray = await strayGameImages(store, ports.held);
  for (const path of stray) console.log(`would remove ${path} from the durable tier: no game row names it`);

  console.log(
    `${leftover.length} left over, ${due.length} due, ${waiting} game picture(s) waiting, ` +
      `${stray.length} to remove. Dry run only. Re-run with --write to apply.`,
  );
} else {
  // Both passes run whatever the other did, and the job fails at the end if
  // either needs a person. One pass throwing used to take the other with it,
  // which is how a single unreadable minimap stopped every game picture too.
  let failed = false;

  try {
    const result = await runPromotion(store, ports, { limit });

    console.log(
      `${result.drained} drained, ${result.promoted} promoted, ${result.deleted} deleted, ${result.skipped} skipped.`,
    );

    // A skip is a row the run read and did not move, which is normal once
    // (something changed underneath it) and a fault if it keeps happening. It is
    // not a reason to fail the run: the rows that did move, moved. A row whose
    // bytes the store would not return is, because nothing will change until
    // somebody looks.
    if (result.skipped > 0) console.log("Skipped rows are listed above with the reason.");
    if (result.unreadable > 0) failed = true;
  } catch (error) {
    console.error("The asset pass stopped:", error);
    failed = true;
  }

  // The game pictures ride the same run and the same ports. After the asset
  // pass, so their files join whatever commit it made or start their own.
  try {
    const images = await runGameImagePromotion(store, ports);
    if (images.skipped > 0) console.log(`${images.skipped} game picture(s) skipped, listed above.`);
    if (images.removed > 0) console.log(`${images.removed} game picture(s) removed, listed above.`);
    if (images.unreadable > 0) failed = true;
  } catch (error) {
    console.error("The game picture pass stopped:", error);
    failed = true;
  }

  if (failed) process.exitCode = 1;
}
