import Link from "next/link";
import { redirect } from "next/navigation";
import { ModerationNav } from "@/components/ModerationNav";
import { decideRequest } from "@/app/games/actions";
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

  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
      <ModerationNav current="games" />
      <div className="flex flex-col gap-6 pt-8">
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
      </div>
    </main>
  );
}
