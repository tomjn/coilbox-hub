import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";
import { unitCompareCached } from "@/lib/games/cached";

/**
 * Two releases of one unit, side by side (#227).
 *
 * The table is the union of both releases' stat keys, so a stat one patch
 * introduced still has its row: the release without it reads as not recorded,
 * which is the fact, rather than the row vanishing and taking the change with
 * it. Changed values are marked, because finding them by eye across two columns
 * of numbers is exactly the work this page exists to save.
 */

export default async function Compare({
  params,
  searchParams,
}: {
  params: Promise<{ shortname: string; unit: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  await connection();
  const { shortname, unit } = await params;
  const query = await searchParams;
  const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);
  const left = first(query.left);
  const right = first(query.right);

  // No pair picked is not an error: it is somebody who followed the breadcrumb
  // from the unit's page without using the form, and the honest answer sends
  // them back there.
  if (!left || !right) notFound();

  const comparison = await unitCompareCached(shortname, unit, left, right);
  if (!comparison) notFound();

  const changed = comparison.rows.filter((row) => row.changed).length;

  return (
    <main className="relative flex-1">
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <nav className="text-sm text-neutral-500" aria-label="Breadcrumb">
          <Link href="/games" className="underline-offset-4 hover:underline active:underline">
            Games
          </Link>
          <span aria-hidden> / </span>
          <Link href={`/games/${shortname}`} className="underline-offset-4 hover:underline active:underline">
            {shortname}
          </Link>
          <span aria-hidden> / </span>
          <Link href={`/games/${shortname}/units`} className="underline-offset-4 hover:underline active:underline">
            Units
          </Link>
          <span aria-hidden> / </span>
          <Link href={`/games/${shortname}/units/${unit}`} className="underline-offset-4 hover:underline active:underline">
            {unit}
          </Link>
          <span aria-hidden> / </span>
          <span className="text-neutral-300">Compare</span>
        </nav>

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

        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-400">
              <th scope="col" className="py-2 pr-4">Stat</th>
              <th scope="col" className="py-2 pr-4">{left}</th>
              <th scope="col" className="py-2 pr-4">{right}</th>
            </tr>
          </thead>
          <tbody>
            {comparison.rows.map((row) => (
              <tr key={row.key} className={`border-b border-neutral-900${row.changed ? " bg-neutral-900/60" : ""}`}>
                <th scope="row" className="py-2 pr-4 text-left font-normal text-neutral-400">
                  {row.label}
                </th>
                <td className="py-2 pr-4 text-neutral-100">{row.left}</td>
                <td className="py-2 pr-4 text-neutral-100">{row.right}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {!comparison.left.found || !comparison.right.found ? (
          <p className="text-sm text-neutral-500">
            A dash means this release has no record of the unit. It may not have shipped yet,
            or the hub may never have been told about it.
          </p>
        ) : null}
      </div>
    </main>
  );
}
