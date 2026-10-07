/**
 * Waiting for a push to the assets repo to become the published site.
 */

export interface DeployWait {
  /** The cheap question asked on every poll while the deploy is awaited. */
  landed(path: string): Promise<boolean>;
  /** Whether the published site answers for a path. */
  served(path: string): Promise<boolean>;
  sleep(ms: number): Promise<unknown>;
  now(): number;
  timeoutMs: number;
  pollMs: number;
}

/**
 * Which of these paths the durable tier is serving, having waited for the
 * deploy that carries the ones this run committed.
 *
 * Pages swaps the whole site at once, so one new path going live means the
 * deploy landed. It has to be a path this run committed: a batch also carries
 * objects an earlier run published, and those answer before the deploy has
 * started. With nothing committed there is no deploy to wait for.
 */
export async function servingAfterDeploy(
  paths: string[],
  committed: ReadonlySet<string>,
  wait: DeployWait,
): Promise<string[]> {
  const awaited = paths.find((path) => committed.has(path));

  if (awaited !== undefined) {
    const deadline = wait.now() + wait.timeoutMs;
    while (!(await wait.landed(awaited)) && wait.now() < deadline) {
      await wait.sleep(wait.pollMs);
    }
  }

  // Confirm every one of them rather than inferring it.
  const live: string[] = [];
  for (const path of paths) {
    if (await wait.served(path)) live.push(path);
  }
  return live;
}
