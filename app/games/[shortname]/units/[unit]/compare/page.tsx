import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import Loading from "@/app/loading";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import { Breadcrumb } from "@/components/Breadcrumb";
import { buttonClass } from "@/components/Button";
import { UnitComparePicker } from "@/components/UnitComparePicker";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import {
  gamePageCached,
  gameReleasesCached,
  unitCompareCached,
  unitNameLabelsCached,
  unitPairCached,
} from "@/lib/games/cached";
import { pickCompareRelease, resolveWithUnit, type PairUnit } from "@/lib/games/compareUnits";
import { gameTitle } from "@/lib/games/labels";
import { compareStatRows } from "@/lib/games/stats";
import { CompareTable } from "./CompareTable";

/**
 * Two releases of one unit, or two units at one release, side by side (#227,
 * #466).
 *
 * `?with=<unit>` compares this unit with another at one release, `release`
 * picking which. `?left=&right=` compares two releases of this unit. `with`
 * wins when both are present. Both draw the same table.
 *
 * The table is the union of both sides' stat keys, so a stat one side lacks
 * still has its row: that side reads as not recorded, which is the fact, rather
 * than the row vanishing and taking the difference with it. Differing values are
 * marked, because finding them by eye across two columns of numbers is exactly
 * the work this page exists to save.
 */

type Params = Promise<{ shortname: string; unit: string }>;
type SearchParams = Promise<Record<string, string | string[] | undefined>>;

const LINK = "underline-offset-4 hover:underline active:underline";

const CHIP_ON = "rounded-md border border-neutral-600 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-100";

const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: SearchParams;
}): Promise<Metadata> {
  const { shortname, unit } = await params;
  const query = await searchParams;

  if (query.with !== undefined) {
    const [game, labels] = await Promise.all([gamePageCached(shortname), unitNameLabelsCached(shortname)]);
    const self = labels.get(unit.toLowerCase());
    if (!game || self?.name !== unit) return { title: "Not found" };
    const resolved = resolveWithUnit(first(query.with), unit, [...labels.values()]);
    const other = resolved.kind === "found" ? resolved.unit.label : "another unit";
    return { title: `Compare ${self.label} with ${other} - ${gameTitle(game)}` };
  }

  const left = first(query.left);
  const right = first(query.right);
  if (!left || !right) return { title: "Not found" };

  // The same two cached reads the page makes, with the same arguments.
  const [game, comparison] = await Promise.all([
    gamePageCached(shortname),
    unitCompareCached(shortname, unit, left, right),
  ]);
  if (!game || !comparison) return { title: "Not found" };
  const label = comparison.left.full_name ?? comparison.right.full_name ?? comparison.unit_name;
  return { title: `Compare ${label} - ${gameTitle(game)}` };
}

interface CompareProps {
  params: Params;
  searchParams: SearchParams;
}

/** The route. Everything on the page depends on the unit and the query string,
 *  and there is no part of it that stays the same between two comparisons, so
 *  the whole page sits behind a boundary of its own. The root loading file's
 *  boundary is already on screen when a visitor moves between two comparisons,
 *  and would hold the old one up until the new one was ready. */
export default function Compare(props: CompareProps) {
  return (
    <Suspense fallback={<Loading />}>
      <CompareContent {...props} />
    </Suspense>
  );
}

async function CompareContent({ params, searchParams }: CompareProps) {
  const { shortname, unit } = await params;
  const query = await searchParams;

  if (query.with !== undefined) {
    return (
      <UnitsComparison
        shortname={shortname}
        unit={unit}
        asked={first(query.with)}
        release={first(query.release)}
      />
    );
  }

  const left = first(query.left);
  const right = first(query.right);

  // No pair picked is not an error: it is somebody who followed the breadcrumb
  // from the unit's page without using the form, and the honest answer sends
  // them back there.
  if (!left || !right) notFound();

  // The same two cached reads the tab title makes.
  const [game, comparison] = await Promise.all([
    gamePageCached(shortname),
    unitCompareCached(shortname, unit, left, right),
  ]);
  if (!comparison) notFound();

  const changed = comparison.rows.filter((row) => row.changed).length;

  return (
    <Frame
      shortname={shortname}
      unit={unit}
      gameName={game ? gameTitle(game) : shortname}
      unitLabel={comparison.left.full_name ?? comparison.right.full_name ?? comparison.unit_name}
    >
      <div className="flex flex-col gap-2">
        <h1 className="text-3xl font-semibold tracking-tight">
          {left} vs {right}
        </h1>
        <p className="text-neutral-400">
          {changed === 0
            ? "Every stat these two releases share reads the same."
            : `${changed} ${changed === 1 ? "stat differs" : "stats differ"} between these releases.`}
        </p>
      </div>

      <CompareTable heads={[left, right]} rows={comparison.rows} />

      {!comparison.left.found || !comparison.right.found ? (
        <p className="text-sm text-neutral-400">
          A dash means this release has no record of the unit. It may not have shipped yet,
          or the hub may never have been told about it.
        </p>
      ) : null}
    </Frame>
  );
}

/** The page around either comparison: the landmark and the breadcrumb. */
function Frame({
  shortname,
  unit,
  gameName,
  unitLabel,
  children,
}: {
  shortname: string;
  unit: string;
  gameName: string;
  unitLabel: string;
  children: ReactNode;
}) {
  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <Breadcrumb
          crumbs={[
            { label: "Games", href: "/games" },
            { label: gameName, href: `/games/${shortname}` },
            { label: "Units", href: `/games/${shortname}/units` },
            { label: unitLabel, href: `/games/${shortname}/units/${unit}` },
            { label: "Compare" },
          ]}
        />
        {children}
      </div>
    </main>
  );
}

const compareHref = (shortname: string, unit: string, other: string, release?: string | null) =>
  `/games/${shortname}/units/${encodeURIComponent(unit)}/compare?with=${encodeURIComponent(other)}` +
  (release ? `&release=${encodeURIComponent(release)}` : "");

/** A column head: the unit's picture and name, linking to its page at the
 *  release being compared. */
function UnitHead({
  shortname,
  unit,
  label,
  picture,
  release,
}: {
  shortname: string;
  unit: string;
  label: string;
  picture: ResolvedAsset | undefined;
  release: string;
}) {
  return (
    <Link
      href={`/games/${shortname}/units/${encodeURIComponent(unit)}?v=${encodeURIComponent(release)}`}
      className="flex w-24 flex-col items-start gap-2 text-sm normal-case tracking-normal text-neutral-100 sm:w-36"
    >
      <span className="flex h-16 w-16 items-center justify-center">
        {!picture || picture.from === "placeholder" ? (
          <AssetPlaceholder of={{ name: unit, keyedOn: "unit", footprint: null }} quiet className="size-16" />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
          <img
            src={picture.url}
            alt={`Buildpic of ${label}`}
            width={64}
            height={64}
            decoding="async"
            className="max-h-16 w-auto object-contain"
          />
        )}
      </span>
      <span className={`break-words ${LINK}`}>{label}</span>
      <span className="break-all font-mono text-xs text-neutral-400">{unit}</span>
    </Link>
  );
}

const nameAt = (unit: PairUnit, release: string) =>
  unit.revisions.find((revision) => revision.version === release)?.full_name ?? unit.full_name ?? unit.unit_name;

/** Two different units. Every way `with` can fail to name one is an ordinary
 *  answer on this page, with the picker beside it, and not a 404. */
async function UnitsComparison({
  shortname,
  unit,
  asked,
  release: askedRelease,
}: {
  shortname: string;
  unit: string;
  asked: string | undefined;
  release: string | undefined;
}) {
  // A hidden game is invisible at every level, as on the game's own page.
  const [game, labels] = await Promise.all([gamePageCached(shortname), unitNameLabelsCached(shortname)]);
  if (!game) notFound();
  const self = labels.get(unit.toLowerCase());
  if (self?.name !== unit) notFound();

  const resolved = resolveWithUnit(asked, unit, [...labels.values()]);
  const picker = (
    <Suspense fallback={null}>
      <UnitComparePicker
        game={shortname}
        unit={unit}
        current={resolved.kind === "found" ? resolved.unit.name : asked?.trim()}
        release={askedRelease || undefined}
        label={resolved.kind === "found" ? "Compare with a different unit" : "Compare with"}
      />
    </Suspense>
  );

  if (resolved.kind !== "found") {
    return (
      <Frame shortname={shortname} unit={unit} gameName={gameTitle(game)} unitLabel={self.label}>
        <h1 className="text-3xl font-semibold tracking-tight">Compare {self.label} with another unit</h1>
        <div className="flex flex-col gap-3 text-neutral-300">
          {resolved.kind === "none" ? (
            <p>Choose a unit from {gameTitle(game)} to compare with {self.label}.</p>
          ) : null}
          {resolved.kind === "self" ? (
            <p>That is the unit you are already looking at. Choose a different one.</p>
          ) : null}
          {resolved.kind === "unknown" ? (
            <p>{gameTitle(game)} has no unit called &quot;{resolved.asked}&quot;. Check the spelling or pick one from the list.</p>
          ) : null}
          {resolved.kind === "ambiguous" ? (
            <>
              <p>More than one unit has that name. Choose one.</p>
              <ul className="flex flex-col gap-1">
                {resolved.choices.map((choice) => (
                  <li key={choice.name}>
                    <Link href={compareHref(shortname, unit, choice.name, askedRelease)} className={LINK}>
                      {choice.label}
                    </Link>{" "}
                    <span className="font-mono text-xs text-neutral-400">{choice.name}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : null}
        </div>
        {picker}
      </Frame>
    );
  }

  const other = resolved.unit;
  const [pair, releases] = await Promise.all([
    unitPairCached(shortname, unit, other.name),
    gameReleasesCached(shortname),
  ]);
  if (!pair) {
    return (
      <Frame shortname={shortname} unit={unit} gameName={gameTitle(game)} unitLabel={self.label}>
        <p className="text-sm text-red-400">The catalog could not be read just now. Try again in a moment.</p>
      </Frame>
    );
  }

  const [a, b] = pair.units;
  const pick = pickCompareRelease(
    releases,
    a.revisions.map((revision) => revision.version),
    b.revisions.map((revision) => revision.version),
    askedRelease || undefined,
  );

  const swap = (
    <Link href={compareHref(shortname, other.name, unit, pick.release)} className={buttonClass("ghost", { size: "md" })}>
      Swap sides
    </Link>
  );
  const heading = (
    <h1 className="text-3xl font-semibold tracking-tight">
      {a.full_name ?? a.unit_name} vs {b.full_name ?? b.unit_name}
    </h1>
  );

  if (!pick.release) {
    const name = (unitName: PairUnit) => unitName.full_name ?? unitName.unit_name;
    const lacking = pick.missing
      ? [pick.missing.a ? name(a) : null, pick.missing.b ? name(b) : null].filter(Boolean)
      : [];
    return (
      <Frame shortname={shortname} unit={unit} gameName={gameTitle(game)} unitLabel={self.label}>
        {heading}
        <div className="flex flex-col gap-3 text-neutral-300">
          {pick.unknownRelease ? <p>The hub holds no release called &quot;{askedRelease}&quot;.</p> : null}
          {lacking.length > 0 ? (
            <p>
              {lacking.join(" and ")} {lacking.length === 1 ? "is" : "are"} not in release {askedRelease}.
            </p>
          ) : null}
          {pick.both.length === 0 ? (
            <p>No release holds both {name(a)} and {name(b)}, so there is nothing to compare them at.</p>
          ) : (
            <>
              <p>Releases that hold both:</p>
              <ReleaseLinks shortname={shortname} unit={unit} other={other.name} releases={pick.both} current={null} />
            </>
          )}
        </div>
        <div className="flex flex-col gap-4 border-t border-neutral-900 pt-6">
          {picker}
          <div>{swap}</div>
        </div>
      </Frame>
    );
  }

  const release = pick.release;
  const rows = compareStatRows(
    a.revisions.find((revision) => revision.version === release)?.stats ?? {},
    b.revisions.find((revision) => revision.version === release)?.stats ?? {},
  );
  const changed = rows.filter((row) => row.changed).length;

  return (
    <Frame shortname={shortname} unit={unit} gameName={gameTitle(game)} unitLabel={self.label}>
      <div className="flex flex-col gap-2">
        {heading}
        <p className="text-neutral-400">
          At release {release}.{" "}
          {changed === 0
            ? "Every stat these two units share reads the same."
            : `${changed} ${changed === 1 ? "stat differs" : "stats differ"} between them.`}
        </p>
      </div>

      <CompareTable
        heads={[
          <UnitHead
            key="a"
            shortname={shortname}
            unit={a.unit_name}
            label={nameAt(a, release)}
            picture={pair.pictures.get(a.unit_name)}
            release={release}
          />,
          <UnitHead
            key="b"
            shortname={shortname}
            unit={b.unit_name}
            label={nameAt(b, release)}
            picture={pair.pictures.get(b.unit_name)}
            release={release}
          />,
        ]}
        rows={rows}
      />
      <p className="text-sm text-neutral-400">A dash means that unit has no value for the stat in this release.</p>

      {pick.both.length > 1 ? (
        <section className="flex flex-col gap-3" aria-labelledby="compare-release">
          <h2 id="compare-release" className="text-sm uppercase tracking-wide text-neutral-400">
            Release
          </h2>
          <ReleaseLinks shortname={shortname} unit={unit} other={other.name} releases={pick.both} current={release} />
        </section>
      ) : null}

      <div className="flex flex-col gap-4 border-t border-neutral-900 pt-6">
        {picker}
        <div>{swap}</div>
      </div>
    </Frame>
  );
}

function ReleaseLinks({
  shortname,
  unit,
  other,
  releases,
  current,
}: {
  shortname: string;
  unit: string;
  other: string;
  releases: string[];
  current: string | null;
}) {
  return (
    <ul className="flex flex-wrap gap-2">
      {releases.map((release) => (
        <li key={release}>
          {release === current ? (
            <span className={CHIP_ON} aria-current="true">
              {release}
            </span>
          ) : (
            <Link href={compareHref(shortname, unit, other, release)} className={buttonClass("ghost", { size: "md" })}>
              {release}
            </Link>
          )}
        </li>
      ))}
    </ul>
  );
}
