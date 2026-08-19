import Link from "next/link";

/**
 * The one bar every moderation page wears.
 *
 * Before this, each page wrote its own two or three links by hand and no two
 * pages agreed. Reports offered four destinations, Pictures offered two, and
 * reaching the trail from the maps queue meant going back to Reports first. The
 * set of pages a moderator can open does not change from page to page, so the
 * links should not either.
 *
 * ## Why the bar is full width
 *
 * The pages underneath it are not one width. The contact sheet needs `max-w-6xl`
 * for its grid and the text pages read better at `max-w-3xl`, so a bar sitting
 * inside the page container would slide sideways as you moved between them. Here
 * it hangs off the same `px-6` gutter as the site header instead, directly under
 * it, and never moves.
 *
 * ## Why the current page is a tab and not just a colour
 *
 * There are three levels of navigation on these pages: the site header, this
 * bar, and pages like the trail that only exist under one section. The
 * underline says which section you are in without reading the heading, and
 * `aria-current` says the same thing to a screen reader. A sub-page keeps its
 * section lit and names itself in a breadcrumb above its own heading.
 */
const SECTIONS = {
  reports: { label: "Reports", href: "/moderation" },
  pictures: { label: "Pictures", href: "/moderation/assets" },
  maps: { label: "Maps", href: "/moderation/maps" },
  authors: { label: "Authors", href: "/moderation/authors" },
  // The meters live outside the moderation folder because they are the hub's
  // bill rather than a queue, but they sit behind the same check and this is
  // the only place anybody would look for them.
  allowances: { label: "Allowances", href: "/ops" },
} as const;

export type ModerationSection = keyof typeof SECTIONS;

// The reading order of the bar. Reports first because it is the page the site
// header sends you to, then the queues, then the bill.
const ORDER: readonly ModerationSection[] = [
  "reports",
  "pictures",
  "maps",
  "authors",
  "allowances",
];

// `py-2` plus the text gives each link a hit area over the 24px minimum, and
// every link carries the border so the row does not shift when one lights up.
// The colour of that border belongs to one of the two states below rather than
// to this string, because two border colours on one element resolve by
// stylesheet order and not by the order they are written here.
const LINK = "-mb-px block border-b-2 px-2 py-2 text-sm transition-colors";
const CURRENT = "border-neutral-500 text-neutral-100";
const OTHER = "border-transparent text-neutral-500 hover:text-neutral-300";

export function ModerationNav({ current }: { current: ModerationSection }) {
  return (
    <nav
      aria-label="Moderation"
      className="relative z-10 border-b border-neutral-900 px-6"
    >
      {/* `-mx-2` cancels the first link's own padding so the row starts on the
          same gutter as the logo above it. */}
      <ul className="-mx-2 flex flex-wrap items-center">
        {ORDER.map((id) => (
          <li key={id}>
            <Link
              href={SECTIONS[id].href}
              aria-current={id === current ? "page" : undefined}
              className={`${LINK} ${id === current ? CURRENT : OTHER}`}
            >
              {SECTIONS[id].label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/**
 * The line above a sub-page's heading, naming the section it belongs to.
 *
 * Only two pages are one level down: the trail under Pictures and a single map
 * under Maps. Neither has a place in the bar, because neither is somewhere you
 * go from anywhere else, and adding them would make five sections look like
 * seven. This says where you are instead, and the link is the way back up.
 */
export function ModerationCrumb({
  parent,
  children,
}: {
  parent: ModerationSection;
  children: React.ReactNode;
}) {
  return (
    <p className="text-sm text-neutral-500">
      <Link
        href={SECTIONS[parent].href}
        className="transition-colors hover:text-neutral-300"
      >
        {SECTIONS[parent].label}
      </Link>{" "}
      / {children}
    </p>
  );
}
