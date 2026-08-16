/**
 * Publishing a seeded corpus one batch at a time (issue #119).
 *
 * Every push to tomjn/coilbox-assets tars, uploads and redeploys the entire
 * published site, because `actions/upload-pages-artifact` has no incremental
 * mode. The cost of publishing is therefore set by the size of the corpus and
 * not by the size of the change, and it grows as the tier fills rather than
 * staying flat.
 *
 * The promotion job (#111) already answers that with one commit and one push
 * per run, whatever the run moves. This is the other half. The seed (#110)
 * arrives with a whole corpus at once, and pushing it in one go risks the ten
 * minute Pages deploy timeout, which leaves a half published site and a script
 * with nowhere clean to resume from.
 *
 * ## What this knows, and what it does not
 *
 * Batches, and nothing else. No format knowledge, no identity rules, no
 * manifest shape: a caller hands over {@link SeedObject}s that already say
 * which batch the bytes belong to, where they are and where they go. Reading
 * the manifest, checking it against the caps and writing the rows are all #110,
 * and keeping them out of here is what makes it possible to kill a run between
 * two deploys in a test.
 *
 * ## The batch number, never a slice of the list
 *
 * coilbox exports rows in walk order rather than batch order, and a file whose
 * bytes an earlier batch already holds is pointed back at that batch rather
 * than stored twice. So a row near the end of the list can name batch 1, and
 * taking the first N rows would take part of a batch and publish part of it.
 * Grouping by the number is the whole of the rule, and it is why this takes the
 * objects rather than a count.
 *
 * ## Resuming, which needs nothing recorded
 *
 * There is no cursor and no state file. A batch an earlier run committed stages
 * nothing, {@link SeedPorts.publish} says so, and this run counts it as already
 * published instead of pushing again. That works because the paths are content
 * addressed: a file that is there is already the right bytes, so a commit that
 * would repeat it is empty rather than a change.
 *
 * A cursor could disagree with the repository, which is the failure worth
 * avoiding. This asks the repository.
 *
 * ## Every batch is confirmed served, including one this run did not push
 *
 * A resumed batch is checked as hard as a fresh one. It costs one round trip
 * per path when the deploy did land, since the tier answers immediately, and it
 * is the only thing standing between "committed" and the belief that it is
 * published.
 *
 * The state it catches is the one the seed cannot get itself out of: a batch
 * that was committed while its deploy failed. Nothing here can fix it, and that
 * is not an oversight. A push is what asks for a deploy, the files are already
 * committed so there is nothing left to push, and asking for a deploy on every
 * resumed batch would rebuild the whole site once per batch, which is the cost
 * this module exists to avoid. So the run stops and says what to do. Somebody
 * re-runs the Pages workflow, and the next run walks past that batch and
 * carries on.
 *
 * Promotion (#111) fails in the same place for the same reason, which is worth
 * knowing before treating either as a bug.
 */

/** One file of the seed export, and the two places it lives. */
export interface SeedObject {
  /** Which batch of the export holds the bytes. The only ordering there is. */
  batch: number;
  /** Where the bytes are now. A path in the export, for the caller to resolve. */
  from: string;
  /** Where they go in the assets checkout. */
  to: string;
}

/** The objects of one batch, in the order the manifest listed them. */
export interface SeedBatch {
  batch: number;
  objects: SeedObject[];
}

/**
 * Everything a run does outside itself, as one set of injected calls.
 *
 * A port per side effect, for the reason `./promote` gives: the guarantee is
 * about the order these happen in and about what an interrupted run leaves
 * behind, and faking them is the only way to stop a run between two of them and
 * look at the state.
 */
export interface SeedPorts {
  /** Whether the assets checkout already holds this path. Content addressed,
   *  so if it does, the bytes there are these bytes and there is nothing to
   *  copy. */
  held(to: string): Promise<boolean>;
  /** Put the bytes in the checkout. */
  copy(from: string, to: string): Promise<void>;
  /**
   * Commit and push. True when something was pushed, false when the batch was
   * already committed and there was nothing to stage.
   *
   * Promotion's equivalent answers with nothing, because one run is one push
   * there and an empty commit ends the run. Here an empty one is the ordinary
   * shape of a resumed run, so the difference has to come back: it is what
   * separates "this run published batch 3" from "batch 3 was already published"
   * in the report, and those are not the same thing to somebody restarting a
   * seed that died.
   */
  publish(paths: string[]): Promise<boolean>;
  /** Which of these the durable tier is serving, having waited a while for the
   *  ones it is not. */
  serving(paths: string[]): Promise<string[]>;
  say(message: string): void;
}

export interface SeedPublishResult {
  /** Batches this run pushed and then saw served. */
  published: number;
  /** Batches an earlier run had already committed. */
  alreadyPublished: number;
  /** Files this run copied into the checkout. */
  copied: number;
}

/**
 * The objects grouped by the batch they belong to, lowest first.
 *
 * Two objects bound for the same path collapse to one. A repeat is not a
 * conflict, since the path is content addressed and both are therefore the same
 * bytes, but publishing it twice would make the copied count claim work that
 * did not happen.
 */
export function seedBatches(objects: SeedObject[]): SeedBatch[] {
  const batches = new Map<number, Map<string, SeedObject>>();

  for (const object of objects) {
    const held = batches.get(object.batch) ?? new Map<string, SeedObject>();
    if (!held.has(object.to)) held.set(object.to, object);
    batches.set(object.batch, held);
  }

  return [...batches.entries()]
    .sort(([one], [other]) => one - other)
    .map(([batch, held]) => ({ batch, objects: [...held.values()] }));
}

/**
 * Publish a seeded corpus, one batch and one deploy at a time.
 *
 * Batches run in order and a batch is not started until the one before it is
 * being served, which is the point: the deploys are the thing being spread out,
 * so overlapping them would spend the whole cost this exists to avoid.
 *
 * A batch the durable tier will not serve stops the run. Later batches are left
 * for the next one, which resumes at the batch that failed, because a run that
 * carried on would pile more pushes on a site that is not deploying.
 */
export async function publishSeedBatches(
  objects: SeedObject[],
  ports: SeedPorts,
): Promise<SeedPublishResult> {
  const result: SeedPublishResult = { published: 0, alreadyPublished: 0, copied: 0 };

  for (const batch of seedBatches(objects)) {
    for (const object of batch.objects) {
      if (await ports.held(object.to)) continue;
      await ports.copy(object.from, object.to);
      result.copied++;
    }

    const paths = batch.objects.map((object) => object.to);
    const pushed = await ports.publish(paths);

    const live = new Set(await ports.serving(paths));
    const missing = paths.filter((path) => !live.has(path));
    if (missing.length > 0) {
      throw new Error(
        `Batch ${batch.batch} is committed and the durable tier is serving ${
          paths.length - missing.length
        } of its ${paths.length} file(s). First missing: ${missing[0]}. ` +
          `Nothing after it has been pushed, so re-run the Pages workflow on the assets repo ` +
          `and run this again, which will pick up from this batch.`,
      );
    }

    if (pushed) {
      result.published++;
      ports.say(`Published batch ${batch.batch}, ${paths.length} file(s).`);
    } else {
      result.alreadyPublished++;
      ports.say(`Batch ${batch.batch} was already published.`);
    }
  }

  return result;
}
