/**
 * Looking after the staging bucket (issue #113): what to delete, and what the
 * meters say.
 *
 *   bun run cleanup:assets --dry-run
 *   bun run cleanup:assets --write
 *
 * Two errands in one job because they are one habit. The sweep needs the same
 * two credentials the report does, runs on the same schedule, and neither is
 * worth a run of its own. The reasoning behind each is in `lib/assets/orphan.ts`
 * and `lib/assets/meters.ts`.
 *
 * A dry run reads Postgres and reports. It deletes nothing, and costs one round
 * trip, so it can be pointed at production to see what the bucket is holding.
 *
 * It sweeps the Supabase bucket only. Vercel Blob is not swept any more, for
 * the reasons `lib/assets/orphan.ts` gives.
 *
 * ## Where it runs, and why not in the hub
 *
 * `.github/workflows/promote.yml` in tomjn/coilbox-assets, as a second step
 * after promotion, on the same daily schedule.
 *
 * It commits nothing, so unlike promotion it has no reason to be in that
 * repository. It is there anyway because of the secret. It needs
 * `SUPABASE_SERVICE_ROLE_KEY`, the most powerful credential the project has,
 * and it is already a repository secret on the assets repo for promotion. A
 * workflow of its own in the hub would mean copying it into a second
 * repository to save nothing.
 *
 * The step runs whether promotion succeeded or not, because a promotion that
 * cannot reach the assets checkout has no bearing on whether a superseded object
 * should still be in the bucket.
 *
 * ## Why a failing run is the alert
 *
 * Running out of an allowance is an outage. There is no alerting in this
 * project: no paging, no webhook, nowhere to send a message. A GitHub Actions run that exits non-zero
 * emails the repository owner, and that is the only channel there is. So the
 * report exits non-zero once a counted meter passes
 * {@link METER_ALERT_FRACTION}, and a red daily run means read the numbers.
 *
 * The sweep still happens first. An alert is not a reason to leave objects in a
 * bucket that is filling up.
 */

export {}; // top level await needs this file to be a module

import { createClient } from "@supabase/supabase-js";
import {
  formatBytes,
  headroom,
  headroomAlerts,
  METER_ALERT_FRACTION,
  fetchMeters,
} from "@/lib/assets/meters";
import {
  CLEANUP_BATCH,
  type CleanupPorts,
  fetchAbandonedStagedDeletions,
  fetchUnclaimedStagedObjects,
  sweepStagedObjects,
} from "@/lib/assets/orphan";
import { removeStagedAssets } from "@/lib/assets/staging";

/** How many paths one bucket delete call names. The same number
 *  `promote-assets.ts` uses. */
const DELETE_CHUNK = 100;

const args = process.argv.slice(2);

function option(name: string): string | undefined {
  const at = args.indexOf(`--${name}`);
  return at === -1 ? undefined : args[at + 1];
}

// `--dry-run` is the default and says so out loud, the way `promote-assets.ts`
// does: an empty string is falsy in a GitHub expression, so a conditional that
// passes no flag at all cannot be written safely.
if (args.includes("--write") && args.includes("--dry-run")) {
  console.error("Asked for both --write and --dry-run. Pick one.");
  process.exit(1);
}

const write = args.includes("--write");
const limit = Number(option("limit") ?? CLEANUP_BATCH);

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
// `promote-assets.ts` gives: Bun loads whichever env file happens to win and the
// two look identical from the output alone.
console.log(`${write ? "Sweeping" : "Reading"} ${new URL(url).host}`);

const supabase = createClient(url, key, {
  auth: { autoRefreshToken: false, persistSession: false },
});

const ports: CleanupPorts = {
  discardStaged: async (paths) => {
    for (let at = 0; at < paths.length; at += DELETE_CHUNK) {
      await removeStagedAssets(supabase, paths.slice(at, at + DELETE_CHUNK));
    }
  },
  say: (message) => console.log(message),
};

// The sweep first. An alert is not a reason to leave objects in a bucket that is
// filling up, and the numbers below are more useful after it than before.
let failed = false;

if (write) {
  try {
    const result = await sweepStagedObjects(supabase, ports, limit);
    console.log(`Bucket: ${result.deleted} deleted, ${result.kept} kept.`);
  } catch (error) {
    console.error("The bucket sweep stopped:", error);
    failed = true;
  }
} else {
  const abandoned = await fetchAbandonedStagedDeletions(supabase);
  for (const path of abandoned) {
    console.log(`would finish deleting ${path} from the bucket, reserved by a run that stopped`);
  }

  const unclaimed = await fetchUnclaimedStagedObjects(supabase, limit);
  for (const object of unclaimed) {
    console.log(`would delete ${object.path} from the bucket (${formatBytes(object.bytes)})`);
  }

  console.log(
    `${abandoned.length + unclaimed.length} unclaimed in the bucket. ` +
      "Dry run only. Re-run with --write to delete them.",
  );
}

const report = await fetchMeters(supabase);

console.log("");
for (const meter of report.meters) {
  const full = headroom(meter);
  const used =
    meter.used === null
      ? "not measurable here"
      : `${formatBytes(meter.used)} of ${formatBytes(meter.allowance)}`;

  console.log(
    `${meter.name}: ${used}${full === null ? "" : ` (${Math.round(full * 100)}%)`} [${meter.basis}]`,
  );
  console.log(`  ${meter.note}`);
}

console.log("");
console.log("Durable tier by class, because a total does not say which one grew:");
if (report.durable.length === 0) {
  console.log("  nothing promoted yet");
}
for (const held of report.durable) {
  console.log(`  ${held.name}: ${held.objects} object(s), ${formatBytes(held.bytes)}`);
}

const alerts = headroomAlerts(report);
if (alerts.length > 0) {
  console.log("");
  for (const alert of alerts) console.error(alert);
  console.error(
    `Past ${Math.round(METER_ALERT_FRACTION * 100)}% of an allowance. This run is failing on ` +
      "purpose: a red scheduled job is the only way this project can reach anybody.",
  );
  process.exit(1);
}

if (failed) process.exitCode = 1;
