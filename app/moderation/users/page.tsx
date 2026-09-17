import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  ACCOUNT_PAGE_SIZE,
  fetchAccounts,
  type ModeratorAccount,
} from "@/lib/moderation/accounts";
import { createClient } from "@/lib/supabase/server";

/**
 * The account directory (issue #385).
 *
 * Moderators could not look up a Hub account anywhere on the hub. The Authors
 * page manages names credited inside map archives, which is a different
 * thing, and `has_capability()` answers for the caller alone, so the
 * capability table is unreadable by design. The function behind this page is
 * the one place that table is asked about somebody else, and the migration
 * carries the argument for why a definer function is the right door.
 *
 * The search posts back to this page with `?q=`, the way the maps page does.
 * Paging rides the same parameter set, so a filtered page number stays
 * meaningful.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-none";

const BUTTON =
  "rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition-colors hover:border-neutral-500 active:border-neutral-400 hover:text-white active:text-white";

const CARD =
  "flex flex-col gap-2 rounded-md border border-neutral-800 bg-neutral-950 p-4 text-sm";

/** One capability chip. The list is short and fixed, so the full names from
 *  lib/access/capability.ts would be noise here; the raw strings are what a
 *  grant row says. */
const CAPABILITY_LABEL: Record<string, string> = {
  can_moderate: "moderator",
  can_publish_unreviewed: "publishes unreviewed",
  can_seed_unit_assets: "seeds pictures",
};

function Account({ account }: { account: ModeratorAccount }) {
  return (
    <li className={CARD}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <span className="font-medium text-neutral-100">
          {account.display_name === "" ? "Unknown" : account.display_name}
        </span>
        <span className="text-xs text-neutral-600">
          {new Date(account.created_at).toISOString().slice(0, 10)}
        </span>
      </div>
      <p className="font-mono text-xs text-neutral-500">{account.id}</p>
      {account.capabilities.length > 0 ? (
        <p className="text-xs text-neutral-400">
          {account.capabilities
            .map((capability) => CAPABILITY_LABEL[capability] ?? capability)
            .join(", ")}
        </p>
      ) : null}
    </li>
  );
}

export default async function Accounts({ searchParams }: PageProps<"/moderation/users">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page: whether
  // this page exists is not something a stranger needs to learn.
  if (!allowed) notFound();

  const { q, page } = await searchParams;
  const term = typeof q === "string" ? q.trim() : "";
  const pageNumber = Number.parseInt(typeof page === "string" ? page : "", 10);
  const current = Number.isFinite(pageNumber) && pageNumber > 0 ? pageNumber : 1;

  const { accounts, error } = await fetchAccounts(
    supabase,
    term === "" ? null : term,
    (current - 1) * ACCOUNT_PAGE_SIZE,
  );

  // A full page may have a next one; a short page cannot. One extra request
  // for a count the pager does not otherwise need is the trade the maps page
  // already declined, so the same shape is kept here.
  const hasNext = accounts.length === ACCOUNT_PAGE_SIZE;
  const previous = current > 1 ? current - 1 : null;
  const next = hasNext ? current + 1 : null;

  const href = (target: number) => {
    const params = new URLSearchParams();
    if (term !== "") params.set("q", term);
    if (target > 1) params.set("page", String(target));
    const query = params.toString();
    return query ? `/moderation/users?${query}` : "/moderation/users";
  };

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="users" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Users</h1>

        <form className="flex flex-wrap gap-3">
          <label className="sr-only" htmlFor="find-an-account">
            Find an account by id or name
          </label>
          <input
            id="find-an-account"
            name="q"
            defaultValue={term}
            placeholder="Find an account by id or name"
            className={`${INPUT} flex-1`}
          />
          <button type="submit" className={BUTTON}>
            Find them
          </button>
        </form>

        {error ? (
          <p role="alert" className="rounded-md border border-red-950 bg-neutral-950 p-6 text-sm text-red-300">
            The directory could not be read: {error}
          </p>
        ) : accounts.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
            {term === ""
              ? "No account has signed in yet."
              : "No account matches that."}
          </p>
        ) : (
          <ul className="flex flex-col gap-3">
            {accounts.map((account) => (
              <Account key={account.id} account={account} />
            ))}
          </ul>
        )}

        {previous !== null || next !== null ? (
          <nav className="flex items-center justify-between text-sm" aria-label="Directory pages">
            {previous !== null ? (
              <Link href={href(previous)} className="text-neutral-300 hover:underline active:underline">
                Newer
              </Link>
            ) : (
              <span />
            )}
            {next !== null ? (
              <Link href={href(next)} className="text-neutral-300 hover:underline active:underline">
                Older
              </Link>
            ) : (
              <span />
            )}
          </nav>
        ) : null}
      </div>
    </main>
  );
}
