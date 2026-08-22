import Link from "next/link";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { TreeNode } from "@/lib/games/tree";

/**
 * One faction's block of the build tree (#228, #266).
 *
 * Three rules keep a request bounded whatever the build graph looks like:
 *
 * - A block renders from its start unit down, not from every unit at once.
 *   Everything the root reaches appears beneath it, which is the whole faction.
 * - One faction per request. The page picks the faction; this component draws
 *   the block it is handed.
 * - A unit expands at most once across the whole tree. Its first occurrence in
 *   the walk opens; every later mention is an edge node, a link that does not
 *   unfold. A commander builds a vehicle plant, the plant builds a construction
 *   vehicle, and the vehicle builds plants again: the reader sees the plant
 *   open once near the top and again underneath the vehicle as a plain link.
 *   Without this, graphs whose builders point at each other draw once per path,
 *   which is how a 379 unit game became a million drawings (#257).
 *
 * There is no depth bound. Each level of recursion claims a unit no earlier
 * level claimed, so the walk cannot outlive the faction's own unit count, and
 * Balanced Annihilation reaches depth 19 - deeper than any bound worth
 * keeping would allow.
 *
 * Every branch renders expanded (#276), and any of them can be collapsed
 * behind its build count. Within a level the walk splits in two: dead ends -
 * units that build nothing - sit together in one horizontal row, and the
 * builders and factories follow underneath as the vertical spine of the
 * hierarchy.
 */

function UnitRow({
  game,
  node,
  picture,
  muted,
}: {
  game: string;
  node: TreeNode;
  picture?: ResolvedAsset;
  muted?: boolean;
}) {
  return (
    <Link
      href={`/games/${game}/units/${node.name}`}
      className={`flex items-center gap-2 ${
        muted
          ? "text-sm text-neutral-400 underline-offset-4 hover:text-white active:text-white hover:underline active:underline"
          : "text-sm text-neutral-300 underline-offset-4 hover:text-white active:text-white hover:underline active:underline"
      }`}
    >
      {picture && picture.from !== "placeholder" ? (
        // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
        <img
          src={picture.url}
          alt=""
          width={picture.width}
          height={picture.height}
          loading="lazy"
          decoding="async"
          className="size-8 shrink-0 object-contain"
        />
      ) : null}
      {node.label}
    </Link>
  );
}

function Node({
  game,
  node,
  byName,
  pictures,
  expanded,
}: {
  game: string;
  node: TreeNode;
  byName: ReadonlyMap<string, TreeNode>;
  pictures: ReadonlyMap<string, ResolvedAsset>;
  /** Every unit already unfolded somewhere above this one, across the whole
   *  page. Shared, so ownership of a subtree is walk order. */
  expanded: Set<string>;
}) {
  const children = node.builds
    .map((name) => byName.get(name))
    .filter((child): child is TreeNode => Boolean(child))
    .map((child) => {
      const fresh = !expanded.has(child.name);
      // Claimed before anything renders, so a later sibling pointing back at
      // this unit sees the claim and draws itself as an edge node.
      if (fresh) expanded.add(child.name);
      return { child, fresh };
    });

  // Dead ends read across; the builders and factories carry the hierarchy
  // down the page.
  const leaves = children.filter(({ child }) => child.builds.length === 0);
  const builders = children.filter(({ child }) => child.builds.length > 0);

  return (
    <li>
      <UnitRow game={game} node={node} picture={pictures.get(node.name)} />
      {children.length > 0 ? (
        <details open className="group">
          <summary className="ml-1 inline cursor-pointer list-none text-xs text-neutral-500 transition-colors hover:text-neutral-300 active:text-neutral-300">
            <span
              aria-hidden
              className="mr-1 inline-block text-center transition-transform group-open:rotate-45"
            >
              +
            </span>
            builds {children.length}
          </summary>
          <ul className="ml-6 mt-1 flex flex-col gap-1.5 border-l border-neutral-800 pl-5">
            {leaves.length > 0 ? (
              <li className="flex flex-wrap items-center gap-x-4 gap-y-1 py-0.5">
                {leaves.map(({ child }) => (
                  <UnitRow
                    key={child.name}
                    game={game}
                    node={child}
                    picture={pictures.get(child.name)}
                    muted
                  />
                ))}
              </li>
            ) : null}
            {builders.map(({ child, fresh }) =>
              fresh ? (
                <Node
                  key={child.name}
                  game={game}
                  node={child}
                  byName={byName}
                  pictures={pictures}
                  expanded={expanded}
                />
              ) : (
                <li key={child.name}>
                  <UnitRow game={game} node={child} picture={pictures.get(child.name)} muted />
                </li>
              ),
            )}
          </ul>
        </details>
      ) : null}
    </li>
  );
}

export function TreeBlock({
  game,
  heading,
  note,
  roots,
  byName,
  pictures,
  expanded,
}: {
  game: string;
  heading: string;
  note?: string;
  /** The units the block hangs from: a faction's start unit, or the ungrouped
   *  units when nothing reaches them. */
  roots: TreeNode[];
  byName: ReadonlyMap<string, TreeNode>;
  pictures: ReadonlyMap<string, ResolvedAsset>;
  expanded: Set<string>;
}) {
  if (roots.length === 0) return null;

  for (const node of roots) expanded.add(node.name);

  return (
    <section className="flex flex-col gap-2" aria-label={heading}>
      <h2 className="text-sm uppercase tracking-wide text-neutral-400">
        {heading}
        {note ? (
          <span className="ml-2 normal-case tracking-normal text-neutral-600">{note}</span>
        ) : null}
      </h2>
      <ul className="flex flex-col gap-1.5">
        {roots.map((node) => (
          <Node
            key={node.name}
            game={game}
            node={node}
            byName={byName}
            pictures={pictures}
            expanded={expanded}
          />
        ))}
      </ul>
    </section>
  );
}
