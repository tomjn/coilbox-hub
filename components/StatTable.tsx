import { formatStatValue, statLabel, statRows, tabularColumns, tabularStatRows } from "@/lib/games/stats";

/**
 * A unit's stats as a table (#227).
 *
 * The rows are `statRows`'s: the keys with names of their own in reading order,
 * everything else alphabetical after them. Nothing is filtered out - a key the
 * hub has never heard of prints under its own name with whatever value arrived,
 * because hiding it would claim the extraction said less than it did.
 *
 * A stat whose value is a list of records, which is what a weapons summary
 * arrives as (#261), draws as its own table instead of a JSON blob: one row per
 * record, columns for whatever those records carry. Column names print by the
 * same rule as stat keys - known ones get their words, unknown ones themselves.
 */

function ValueTable({ rows }: { rows: Record<string, unknown>[] }) {
  const columns = tabularColumns(rows);
  return (
    <table className="w-full max-w-xl text-sm">
      <thead>
        <tr>
          {columns.map((column) => (
            <th
              key={column}
              scope="col"
              className="border-b border-neutral-800 px-2 py-1 text-left font-normal text-neutral-400"
            >
              {statLabel(column)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr key={index}>
            {columns.map((column) => (
              <td key={column} className="px-2 py-1 text-neutral-100">
                {formatStatValue(row[column] ?? null)}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function StatTable({ stats }: { stats: Record<string, unknown> }) {
  const rows = statRows(stats);
  if (rows.length === 0) {
    return <p className="text-sm text-neutral-500">No stats reported for this unit yet.</p>;
  }

  return (
    <dl className="grid grid-cols-[minmax(8rem,1fr)_2fr] gap-x-4 gap-y-1.5 text-sm">
      {rows.map((row) => {
        const tabular = tabularStatRows(stats[row.key]);
        return (
          <div key={row.key} className="col-span-2 grid grid-cols-subgrid">
            <dt className="text-neutral-400">{row.label}</dt>
            <dd className="text-neutral-100">
              {tabular ? <ValueTable rows={tabular} /> : formatStatValue(stats[row.key])}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}
