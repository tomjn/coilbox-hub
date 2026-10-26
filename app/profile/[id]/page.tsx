import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { ContributorSections } from "@/components/ContributorSections";
import { contributionsCached } from "@/lib/profile/cached";
import { type Contributions, parseAccountId } from "@/lib/profile/contributions";

/**
 * What one account has sent the hub (issue #293), at a URL keyed on the account
 * id so a rename does not break a link. A malformed id and an account with no
 * profile row are both not found.
 */

const load = cache(async (raw: string): Promise<{ id: string; contributions: Contributions } | null> => {
  const id = parseAccountId(raw);
  if (!id) return null;
  const contributions = await contributionsCached(id);
  return contributions ? { id, contributions } : null;
});

// Content is sparse here, closer to the landing page than to the gallery.
const BACKDROP_STRENGTH = 0.09;

/** One placeholder, which renders as not found. On Vercel an unlisted page is
 *  upgraded to a whole static page after its first visit only when its segment
 *  has a `generateStaticParams`, and Cache Components refuses an empty list. */
export function generateStaticParams() {
  return [{ id: "__none__" }];
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const found = await load((await params).id);
  if (!found) return { title: "Not found" };
  return { title: found.contributions.name };
}

export default async function Profile({ params }: { params: Promise<{ id: string }> }) {
  const found = await load((await params).id);
  if (!found) notFound();

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={games} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-12">
        <h1 className="break-words text-3xl font-semibold tracking-tight">
          {found.contributions.name}
        </h1>
        <ContributorSections accountId={found.id} contributions={found.contributions} />
      </div>
    </main>
  );
}
