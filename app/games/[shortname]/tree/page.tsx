import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";
import { TreeBlock } from "@/components/GameTree";
import { FactionToggles } from "@/components/FactionToggles";
import { gameFactionsCached, treeCached, treeUnitPicturesCached } from "@/lib/games/cached";
import type { TreeNode } from "@/lib/games/tree";

/**
 * The build tree (#228).
 *
 * A faction's units, walkable down through what each thing builds, rooted at
 * the start units. The grouping is coilbox's own walk, and the reason it is a
 * grouping rather than a hierarchy is upstream's too: a unit two builders make
 * appears once under its faction, and every builder that can make it lists it
 * again under itself, so nothing is invisible because one parent won.
 *
 * ## No bundle
 *
 * Expanding is `<details>`, searching is a GET form, the version picker is
 * links. Every interaction here is HTML that works with scripting off, which is
 * stronger than rendering the first faction server side and hoping the bundle
 * takes over: there is no bundle to wait for at all.
 */

export async function generateMetadata({
  params,
}: {
  params: Promise<{ shortname: string }>;
}): Promise<Metadata> {
  const { shortname } = await params;
  return {
    title: `${shortname} build tree - Coilbox Hub`,
    description: `What every faction in ${shortname} can reach, from its start units.`,
  };
}

export default async function TreePage({
  params,
  searchParams,
}: {
  params: Promise<{ shortname: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  await connection();
  const { shortname } = await params;
  const query = await searchParams;
  const raw = (key: string) => {
    const value = query[key];
    return Array.isArray(value) ? value[0] : value;
  };
  const v = raw("v");
  const factionParam = raw("faction")?.trim() || null;

  // One faction per request (#266): the chosen one, or the game's first. The
  // walk is scoped to that side's units before it runs, so a request never
  // carries two factions' graphs.
  const factions = await gameFactionsCached(shortname);
  const faction = factionParam ?? factions[0]?.key ?? null;

  const tree = await treeCached(shortname, v, faction);
  if (!tree) notFound();

  // Every unit the tree holds, so any build option resolves to its subtree.
  const byName = new Map<string, TreeNode>();
  for (const faction of tree.factions) {
    for (const node of faction.units) byName.set(node.name, node);
  }
  for (const node of tree.ungrouped) byName.set(node.name, node);

  // One batched read for every buildpic the page draws.
  const pictures = await treeUnitPicturesCached(shortname, [...byName.keys()]);

  // Shared across every block on the page: a unit unfolds at most once per
  // request, wherever in the walk its first occurrence lands (#266).
  const expanded = new Set<string>();

  // Sides as toggles rather than a dropdown (#269), and the only control on
  // the page (#271): search had nothing to say here, since matching units
  // whose ancestors do not match draws nothing useful from a walk.
  const factionHref = (key: string) => {
    const query = new URLSearchParams();
    if (v) query.set("v", v);
    query.set("faction", key);
    return `/games/${shortname}/tree?${query.toString()}`;
  };
  const factionOptions = factions.map((option) => ({
    key: option.key,
    label: option.name,
    href: factionHref(option.key),
    active: faction === option.key,
  }));

  return (
    <main className="relative flex-1">
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <nav className="text-sm text-neutral-500" aria-label="Breadcrumb">
          <Link href={`/games/${shortname}`} className="underline-offset-4 hover:underline active:underline">
            {shortname}
          </Link>
          <span aria-hidden> / </span>
          <span className="text-neutral-300">Build tree</span>
        </nav>

        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Build tree</h1>
          <p className="text-neutral-400">
            What each faction can reach, starting from its start unit. Open a unit to see what it
            builds.
          </p>
        </div>

        {factions.length > 1 ? <FactionToggles options={factionOptions} /> : null}

        {tree.factions.length === 0 && tree.ungrouped.length === 0 ? (
          <p className="text-sm text-neutral-500">Nobody has reported this game&rsquo;s units yet.</p>
        ) : (
          <div className="flex flex-col gap-8">
            {tree.factions.map((faction) => {
              // The block hangs from its start unit (#266); the walk beneath
              // it reaches everything else in the faction.
              const rootNode = faction.units.find((node) => node.name === faction.root);
              return rootNode ? (
                <TreeBlock
                  key={faction.root}
                  game={shortname}
                  heading={faction.label}
                  note={`${faction.units.length} units`}
                  roots={[rootNode]}
                  byName={byName}
                  pictures={pictures}
                  expanded={expanded}
                />
              ) : null;
            })}
            {tree.ungrouped.length > 0 ? (
              <TreeBlock
                game={shortname}
                heading="No faction reaches these"
                note={`${tree.ungrouped.length} units`}
                roots={tree.ungrouped}
                byName={byName}
                pictures={pictures}
                expanded={expanded}
              />
            ) : null}
          </div>
        )}

        <p className="text-sm text-neutral-500">
          Facts as of{" "}
          {v ? (
            <>release {v}</>
          ) : (
            <>
              the latest reports.{" "}
              <Link href={`/games/${shortname}`} className="text-neutral-300 underline-offset-4 hover:underline active:underline">
                Back to the game
              </Link>
            </>
          )}
          .
        </p>
      </div>
    </main>
  );
}
