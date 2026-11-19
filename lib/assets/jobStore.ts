import type { GameImage } from "./promoteGameImages";
import type { MeterReport } from "./meters";
import type { UnclaimedStagedObject } from "./orphan";
import { type PendingDeletion, type PromotableRow, StagingReadError } from "./promote";
import type { OutstandingWithdrawal } from "./withdraw";

/**
 * What the promotion job and the bucket sweep ask of the hub (#616).
 *
 * They used to ask Supabase with the service role key. Since pictures and
 * games moved to the hub's MariaDB and staged pictures to the droplet's disk,
 * the hub answers instead, at `/api/v1/promotion`, with a token that opens
 * nothing else. The routes and the rules the hub keeps are in
 * `hub-php/app/Domain/Promotion/PromotionJob.php`.
 *
 * An interface rather than the client itself, so the tests can kill a run at
 * any of these calls and look at what it left behind.
 */
export interface PromotionStore {
  /** Safety rejections of promoted pictures still in the durable tier, oldest first. */
  withdrawals(): Promise<OutstandingWithdrawal[]>;
  /** Settles takedowns. Answers how many were outstanding. */
  recordWithdrawn(ids: string[]): Promise<number>;
  /** Approved pictures past the moderation hold, oldest approval first. The hub works out the hold. */
  due(limit: number): Promise<PromotableRow[]>;
  /** Which of these are still approved and on the staging tier. */
  stillDue(ids: string[]): Promise<string[]>;
  /** Rows whose staged object is still waiting to be deleted. */
  pendingDeletions(): Promise<PendingDeletion[]>;
  /** `promote_assets`: the rows that moved, each with its old staging path. */
  promote(ids: string[], paths: string[]): Promise<{ id: string; blob_path: string }[]>;
  /** `clear_promoted_blob_paths`. */
  clearPromoted(ids: string[]): Promise<number>;
  /** A staged object's bytes. Throws {@link StagingReadError}, 404 when the hub has nothing the job may read there. */
  readStaged(path: string): Promise<Uint8Array>;
  /** `reserve_staged_deletions`: the paths reserved, which are the ones to delete. */
  reserve(paths: string[], queuedIsClaimed: boolean): Promise<string[]>;
  /** Deletes staged objects. The hub refuses unless every path is reserved. */
  discard(paths: string[]): Promise<void>;
  /** `release_staged_deletions`. */
  release(paths: string[]): Promise<number>;
  /** Staged objects nothing claims, oldest first. */
  unclaimed(limit: number): Promise<UnclaimedStagedObject[]>;
  /** Paths reserved by a run that died more than an hour ago. */
  abandoned(): Promise<string[]>;
  /** Every game row's picture columns. */
  games(): Promise<GameImageRow[]>;
  /** Clears a game picture's staged tier while the row still names this path and hash. */
  clearGameStagedTier(image: GameImage): Promise<boolean>;
  /** The meters, as the hub's allowances page shows them. */
  meters(): Promise<MeterReport>;
}

/** A game row as `games()` answers: the shortname and `<kind>_path`, `_hash` and `_staged_tier` per kind. */
export type GameImageRow = { shortname: string } & Record<string, string | null>;

/** The hub's answer to a request, or an error saying which one failed and why. */
async function answer(response: Response, what: string): Promise<unknown> {
  if (response.ok) return await response.json();
  let reason = response.statusText;
  try {
    const body = (await response.json()) as { error?: string };
    if (body.error) reason = body.error;
  } catch {
    // Not JSON, so the status text is all there is.
  }
  throw new Error(`${response.status} from the hub for ${what}: ${reason}`);
}

/**
 * The hub over HTTP. `base` is the hub's address, such as
 * `https://hub.coilbox.org`, and `token` the job's token.
 */
export function hubPromotionStore(
  base: string,
  token: string,
  fetcher: typeof fetch = fetch,
): PromotionStore {
  const root = `${base.replace(/\/+$/, "")}/api/v1/promotion`;
  const headers = { authorization: `Bearer ${token}`, accept: "application/json" };

  const get = async <T>(path: string): Promise<T> =>
    (await answer(await fetcher(`${root}/${path}`, { headers }), path)) as T;

  const post = async <T>(path: string, body: unknown): Promise<T> =>
    (await answer(
      await fetcher(`${root}/${path}`, {
        method: "POST",
        headers: { ...headers, "content-type": "application/json" },
        body: JSON.stringify(body),
      }),
      path,
    )) as T;

  return {
    withdrawals: () => get("withdrawals"),
    recordWithdrawn: async (ids) => (await post<{ settled: number }>("withdrawals/settle", { ids })).settled,
    due: (limit) => get(`due?limit=${limit}`),
    stillDue: async (ids) => (await post<{ ids: string[] }>("due/check", { ids })).ids,
    pendingDeletions: () => get("pending-deletions"),
    promote: (ids, paths) => post("promote", { ids, paths }),
    clearPromoted: async (ids) =>
      (await post<{ cleared: number }>("pending-deletions/clear", { ids })).cleared,

    readStaged: async (path) => {
      const response = await fetcher(`${root}/staged?path=${encodeURIComponent(path)}`, { headers });
      if (!response.ok) throw new StagingReadError(response.status, `the hub's staged ${path}`);
      return new Uint8Array(await response.arrayBuffer());
    },
    reserve: async (paths, queuedIsClaimed) =>
      (await post<{ paths: string[] }>("staged/reserve", { paths, queued_is_claimed: queuedIsClaimed })).paths,
    discard: async (paths) => {
      await post("staged/delete", { paths });
    },
    release: async (paths) => (await post<{ released: number }>("staged/release", { paths })).released,
    unclaimed: (limit) => get(`staged/unclaimed?limit=${limit}`),
    abandoned: () => get("staged/abandoned"),

    games: () => get("games"),
    clearGameStagedTier: async (image) =>
      (
        await post<{ cleared: boolean }>("games/clear-staged", {
          shortname: image.shortname,
          kind: image.kind,
          path: image.path,
          hash: image.hash,
        })
      ).cleared,

    meters: () => get("meters"),
  };
}

/**
 * The store the scripts use, from `HUB_URL` and `PROMOTION_TOKEN`, or the
 * reason it cannot be made.
 */
export function promotionStoreFromEnv(
  env: Record<string, string | undefined> = process.env,
): { store: PromotionStore; host: string } | { error: string } {
  const url = env.HUB_URL;
  const token = env.PROMOTION_TOKEN;
  if (!url || !token) {
    return {
      error:
        "Need HUB_URL, the hub's address, and PROMOTION_TOKEN, the token `php artisan promotion:token` made.",
    };
  }
  return { store: hubPromotionStore(url, token), host: new URL(url).host };
}
