import Link from "next/link";
import { matchesQuery, type TreeNode } from "@/lib/games/tree";

/**
 * One faction's block of the build tree (#228), and the units inside it.
 *
 * A unit is unfolded once per path that reaches it, never per lap of a loop:
 * build graphs contain cycles (a kbot lab builds a construction kbot that
 * builds the lab again), so a child already on the path above it is drawn as a
 * plain link and not expanded. Walking `lab > kbot > lab` tells a reader
 * nothing the first visit did not, and without this cut a fixed depth bound
 * does not stop early - it draws the loop until it runs out of depth, which is
 * how a 379 unit game became a million drawings (#257).
 */

/** How deep the walk may nest before it stops. The ancestor check above ends
 *  loops on its own; this bounds the rare graph whose paths are long without
 *  ever repeating a unit. */
const MAX_DEPTH = 8;

function Node({
  game,
  node,
  byName,
  q,
  depth,
  ancestors,
}: {
  game: string;
  node: TreeNode;
  byName: ReadonlyMap<string, TreeNode>;
  q: string | null;
  depth: number;
  ancestors: ReadonlySet<string>;
}) {
  const children = node.builds
    .map((name) => byName.get(name))
    .filter(
      (child): child is TreeNode =>
        Boolean(child) && !ancestors.has((child as TreeNode).name) && matchesQuery(child as TreeNode, q),
    );

  return (
    <li>
      <Link
        href={`/games/${game}/units/${node.name}`}
        className="text-sm text-neutral-300 underline-offset-4 hover:text-white active:text-white hover:underline active:underline"
      >
        {node.label}
      </Link>
      {children.length > 0 ? (
        <details>
          <summary className="ml-1 inline cursor-pointer list-none text-xs text-neutral-500 transition-colors hover:text-neutral-300 active:text-neutral-300">
            builds {children.length}
          </summary>
          <ul className="ml-3 mt-1 flex flex-col gap-1 border-l border-neutral-900 pl-3">
            {depth < MAX_DEPTH
              ? children.map((child) => (
                  <Node
                    key={child.name}
                    game={game}
                    node={child}
                    byName={byName}
                    q={q}
                    depth={depth + 1}
                    ancestors={new Set([...ancestors, node.name])}
                  />
                ))
              : children.map((child) => (
                  <li key={child.name}>
                    <Link
                      href={`/games/${game}/units/${child.name}`}
                      className="text-sm text-neutral-400 underline-offset-4 hover:text-white active:text-white hover:underline active:underline"
                    >
                      {child.label}
                    </Link>
                  </li>
                ))}
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
  nodes,
  byName,
  q,
}: {
  game: string;
  heading: string;
  note?: string;
  nodes: TreeNode[];
  byName: ReadonlyMap<string, TreeNode>;
  q: string | null;
}) {
  const visible = nodes.filter((node) => matchesQuery(node, q));
  if (visible.length === 0) return null;

  return (
    <section className="flex flex-col gap-2" aria-label={heading}>
      <h2 className="text-sm uppercase tracking-wide text-neutral-400">
        {heading}
        {note ? (
          <span className="ml-2 normal-case tracking-normal text-neutral-600">{note}</span>
        ) : null}
      </h2>
      <ul className="flex flex-col gap-1.5">
        {visible.map((node) => (
          <Node key={node.name} game={game} node={node} byName={byName} q={q} depth={0} ancestors={new Set()} />
        ))}
      </ul>
    </section>
  );
}
