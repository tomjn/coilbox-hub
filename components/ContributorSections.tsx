import Link from "next/link";
import type { ReactNode } from "react";
import type { Contributions } from "@/lib/profile/contributions";

function count(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

function Section({
  title,
  summary,
  children,
}: {
  title: string;
  summary: string;
  children?: ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
      <div className="flex flex-col gap-1">
        <h2 className="text-lg font-medium">{title}</h2>
        <p className="text-sm text-neutral-400">{summary}</p>
      </div>
      {children}
    </section>
  );
}

const LINK = "text-sm text-neutral-200 underline-offset-4 hover:underline active:underline";

/**
 * One section per kind of contribution (issue #293).
 *
 * The wording is deliberate. The account on a map or game is whoever ran the
 * client that sent the facts, which is not the person credited in the archive,
 * so those sections say "sent the facts for" and never "made".
 */
export function ContributorSections({
  accountId,
  contributions,
}: {
  accountId: string;
  contributions: Contributions;
}) {
  const { maps, pictures, games, items } = contributions;

  return (
    <div className="flex flex-col gap-8">
      <Section
        title="Maps"
        summary={
          maps.count === 0
            ? "No maps yet."
            : `Sent the facts for ${count(maps.count, "map", "maps")}. The mapper is credited on each map's own page.`
        }
      >
        {maps.recent.length > 0 ? (
          <ul className="flex flex-col gap-1.5">
            {maps.recent.map((map) => (
              <li key={map.slug} className="break-words">
                <Link href={`/map/${map.slug}`} className={LINK}>
                  {map.label}
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
      </Section>

      <Section
        title="Pictures"
        summary={
          pictures.count === 0
            ? "No approved pictures yet."
            : `Uploaded ${count(pictures.count, "picture", "pictures")} of units and maps that a moderator has approved.`
        }
      />

      <Section
        title="Games"
        summary={
          games.count === 0
            ? "No games yet."
            : `Sent the facts for ${count(games.count, "game", "games")}.`
        }
      >
        {games.recent.length > 0 ? (
          <ul className="flex flex-col gap-1.5">
            {games.recent.map((game) => (
              <li key={game.shortname} className="break-words">
                <Link href={`/games/${game.shortname}`} className={LINK}>
                  {game.label}
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
      </Section>

      <Section
        title="Gallery"
        summary={
          items.count === 0
            ? "Nothing published yet."
            : `Published ${count(items.count, "item", "items")}.`
        }
      >
        {items.recent.length > 0 ? (
          <>
            <ul className="flex flex-col gap-1.5">
              {items.recent.map((item) => (
                <li key={item.id} className="break-words">
                  <Link href={`/item/${item.id}`} className={LINK}>
                    {item.title}
                  </Link>
                </li>
              ))}
            </ul>
            <Link href={`/gallery?account=${accountId}`} className={LINK}>
              See everything they have published
            </Link>
          </>
        ) : null}
      </Section>
    </div>
  );
}
