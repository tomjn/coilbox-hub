import { formatStatValue, statRows } from "@/lib/games/stats";

/**
 * A unit's stats as a table (#227).
 *
 * The rows are `statRows`'s: the keys with names of their own in reading order,
 * everything else alphabetical after them. Nothing is filtered out - a key the
 * hub has never heard of prints under its own name with whatever value arrived,
 * because hiding it would claim the extraction said less than it did.
 */

export function StatTable({ stats }: { stats: Record<string, unknown> }) {
  const rows = statRows(stats);
  if (rows.length === 0) {
    return <p className="text-sm text-neutral-500">No stats reported for this unit yet.</p>;
  }

  return (
    <dl className="grid grid-cols-[minmax(8rem,1fr)_2fr] gap-x-4 gap-y-1.5 text-sm">
      {rows.map((row) => (
        <div key={row.key} className="col-span-2 grid grid-cols-subgrid">
          <dt className="text-neutral-400">{row.label}</dt>
          <dd className="text-neutral-100">{formatStatValue(stats[row.key])}</dd>
        </div>
      ))}
    </dl>
  );
}
