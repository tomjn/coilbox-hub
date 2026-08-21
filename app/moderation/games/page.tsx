import Link from "next/link";
import { redirect } from "next/navigation";
import { ModerationNav } from "@/components/ModerationNav";
import { decideRequest, setGameVisibility, setVersionVisibility } from "@/app/games/actions";
import { createClient } from "@/lib/supabase/server";

/**
 * The ownership queue (#229).
 *
 * One card per open ask: who wants which game, what they said about why, and
 * the two decisions. Approving moves ownership inside the action, in the same
 * breath as the state, so an approved ask can never sit beside an unowned game.
 *
 * The page reads through row level security with the visitor's own client, so
 * "is this a moderator" is not a check here at all: a non-moderator's session
 * sees no rows and no queue. The nav bar is the only thing that would render,
 * and `is_moderator()` gates it exactly as it gates every other section.
 */

export default async function ModerationGames() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) redirect("/moderation");

  const { data: requests } = await supabase
    .from("game_ownership_request")
    .select("id,note,created_at,requested_by_name,game(shortname)")
    .order("created_at", { ascending: true });

  const queue = (requests ?? []) as unknown as {
    id: number;
    note: string | null;
    created_at: string;
    requested_by_name: string;
    game: { shortname: string };
  }[];

  // The visibility half of the page. A moderator's session sees through hides
  // at the policy layer, so these two reads are the full management lists.
  const [hiddenGames, hiddenVersions] = await Promise.all([
    supabase.from("game").select("shortname,hidden_at").not("hidden_at", "is", null),
    supabase
      .from("game_version")
      .select("version,hidden_at,game(shortname)")
      .not("hidden_at", "is", null)
      .order("hidden_at", { ascending: false }),
  ]);

  const hiddenGameRows = (hiddenGames.data ?? []) as unknown as {
    shortname: string;
  }[];
  const hiddenVersionRows = (hiddenVersions.data ?? []) as unknown as {
    version: string;
    game: { shortname: string };
  }[];

  return (
    <main className="relative flex-1">
      <ModerationNav current="games" />
      <div className="mx-auto w-full max-w-3xl flex flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-2xl font-semibold tracking-tight">Ownership requests</h1>
          <p className="text-sm text-neutral-400">
            People asking to hold the pen on a game&rsquo;s page. Approving hands them the
            display name, description, links, snippets and images.
          </p>
        </div>

        {queue.length === 0 ? (
          <p className="text-sm text-neutral-500">Nobody is asking right now.</p>
        ) : (
          <ul className="flex flex-col gap-4">
            {queue.map((request) => (
              <li key={request.id} className="flex flex-col gap-3 rounded-md border border-neutral-900 p-4">
                <div className="flex flex-col gap-1">
                  <Link
                    href={`/games/${request.game.shortname}`}
                    className="font-medium text-neutral-100 underline-offset-4 hover:underline active:underline"
                  >
                    {request.game.shortname}
                  </Link>
                  <p className="text-sm text-neutral-400">
                    {request.requested_by_name}, {new Date(request.created_at).toLocaleDateString()}
                  </p>
                  {request.note ? (
                    <p className="text-sm text-neutral-300">{request.note}</p>
                  ) : null}
                </div>
                <form action={decideRequest} className="flex gap-2">
                  <input type="hidden" name="request_id" value={request.id} />
                  <button
                    type="submit"
                    name="approve"
                    value="true"
                    className="rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-100 transition-colors hover:border-neutral-500 active:border-neutral-400"
                  >
                    Approve
                  </button>
                  <button
                    type="submit"
                    name="approve"
                    value="false"
                    className="rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
                  >
                    Decline
                  </button>
                </form>
              </li>
            ))}
          </ul>
        )}

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-visibility">
          <h2 id="mod-visibility" className="text-sm uppercase tracking-wide text-neutral-400">
            Visibility
          </h2>

          <form action={setGameVisibility} className="flex items-end gap-2">
            <input type="hidden" name="hidden" value="true" />
            <div className="flex flex-col gap-1.5">
              <label htmlFor="hide-shortname" className="text-xs uppercase tracking-wide text-neutral-500">
                Hide a game
              </label>
              <input
                id="hide-shortname"
                name="shortname"
                placeholder="Shortname, e.g. BA"
                required
                maxLength={64}
                className="w-48 rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
              />
            </div>
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-3 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
            >
              Hide
            </button>
          </form>

          <form action={setVersionVisibility} className="flex items-end gap-2">
            <input type="hidden" name="hidden" value="true" />
            <div className="flex flex-col gap-1.5">
              <label htmlFor="hide-version-game" className="text-xs uppercase tracking-wide text-neutral-500">
                Hide a release
              </label>
              <div className="flex gap-2">
                <input
                  id="hide-version-game"
                  name="shortname"
                  placeholder="Shortname"
                  required
                  maxLength={64}
                  className="w-36 rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
                />
                <input
                  name="version"
                  placeholder="Release, e.g. 1.9.0"
                  required
                  maxLength={64}
                  aria-label="Release to hide"
                  className="w-44 rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
                />
              </div>
            </div>
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-3 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
            >
              Hide
            </button>
          </form>

          {hiddenGameRows.length > 0 || hiddenVersionRows.length > 0 ? (
            <div className="flex flex-col gap-3 pt-2">
              {hiddenGameRows.length > 0 ? (
                <div className="flex flex-col gap-1.5">
                  <p className="text-xs uppercase tracking-wide text-neutral-500">Hidden games</p>
                  <ul className="flex flex-col gap-1.5">
                    {hiddenGameRows.map((row) => (
                      <li key={row.shortname} className="flex items-center justify-between gap-3 text-sm">
                        <span className="font-mono text-neutral-300">{row.shortname}</span>
                        <form action={setGameVisibility}>
                          <input type="hidden" name="shortname" value={row.shortname} />
                          <input type="hidden" name="hidden" value="false" />
                          <button
                            type="submit"
                            className="rounded-md border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
                          >
                            Unhide
                          </button>
                        </form>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {hiddenVersionRows.length > 0 ? (
                <div className="flex flex-col gap-1.5">
                  <p className="text-xs uppercase tracking-wide text-neutral-500">Hidden releases</p>
                  <ul className="flex flex-col gap-1.5">
                    {hiddenVersionRows.map((row) => (
                      <li
                        key={`${row.game.shortname}:${row.version}`}
                        className="flex items-center justify-between gap-3 text-sm"
                      >
                        <span className="font-mono text-neutral-300">
                          {row.game.shortname} {row.version}
                        </span>
                        <form action={setVersionVisibility}>
                          <input type="hidden" name="shortname" value={row.game.shortname} />
                          <input type="hidden" name="version" value={row.version} />
                          <input type="hidden" name="hidden" value="false" />
                          <button
                            type="submit"
                            className="rounded-md border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
                          >
                            Unhide
                          </button>
                        </form>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </div>
          ) : null}
        </section>
      </div>
    </main>
  );
}
