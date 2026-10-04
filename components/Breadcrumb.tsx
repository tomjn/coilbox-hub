import Link from "next/link";
import { Fragment } from "react";
import { gamePageCached } from "@/lib/games/cached";
import { gameTitle } from "@/lib/games/labels";

/**
 * The line above a game page's heading (#495).
 *
 * Every game page builds it here so the pages cannot disagree about what a
 * crumb says. A crumb with an `href` is a link, and the last one is the page
 * the visitor is on. A long name is cut short with CSS and kept whole in the
 * `title`, so it cannot push the row wider than a phone.
 */

export interface Crumb {
  label: string;
  href?: string;
}

const LINK = "underline-offset-4 hover:underline active:underline";
const CLIP = "inline-block max-w-xs truncate align-bottom";

export function Breadcrumb({ crumbs }: { crumbs: Crumb[] }) {
  return (
    <nav className="text-sm text-neutral-400" aria-label="Breadcrumb">
      {crumbs.map((crumb, index) => {
        const last = index === crumbs.length - 1;
        return (
          <Fragment key={`${index}-${crumb.label}`}>
            {index > 0 ? <span aria-hidden> / </span> : null}
            {crumb.href && !last ? (
              <Link href={crumb.href} title={crumb.label} className={`${CLIP} ${LINK}`}>
                {crumb.label}
              </Link>
            ) : (
              <span
                title={crumb.label}
                aria-current={last ? "page" : undefined}
                className={`${CLIP} text-neutral-300`}
              >
                {crumb.label}
              </span>
            )}
          </Fragment>
        );
      })}
    </nav>
  );
}

/**
 * The crumbs of a page one level under a game: the game's name, then what the
 * page is. Reads `params`, so the caller puts it behind a boundary. The name
 * comes from the cached read the page's tab title already makes, and the
 * shortname stands in for a game the hub does not show.
 */
export async function GameBreadcrumb({
  params,
  current,
}: {
  params: Promise<{ shortname: string }>;
  current: string;
}) {
  const { shortname } = await params;
  const game = await gamePageCached(shortname);
  return (
    <Breadcrumb
      crumbs={[
        { label: game ? gameTitle(game) : shortname, href: `/games/${shortname}` },
        { label: current },
      ]}
    />
  );
}
