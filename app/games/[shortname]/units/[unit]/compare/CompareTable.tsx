import type { ReactNode } from "react";
import type { CompareRow } from "@/lib/games/stats";

/**
 * The side by side table both kinds of comparison draw: two releases of one
 * unit, and two units at one release (#466). The caller supplies the two column
 * heads. The rows, and the marking of the ones that differ, are the same.
 *
 * It sits in a scroll container, because a stat such as a weapons list prints
 * as one long line and a phone is narrower than two of them.
 */
export function CompareTable({
  heads,
  rows,
}: {
  heads: [ReactNode, ReactNode];
  rows: CompareRow[];
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-400">
            <th scope="col" className="py-2 pr-4 align-bottom">Stat</th>
            <th scope="col" className="py-2 pr-4 align-bottom">{heads[0]}</th>
            <th scope="col" className="py-2 pr-4 align-bottom">{heads[1]}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className={`border-b border-neutral-900${row.changed ? " bg-neutral-900/60" : ""}`}>
              <th scope="row" className="py-2 pr-4 text-left align-top font-normal text-neutral-400">
                {row.label}
                {row.changed ? <span className="sr-only"> (differs)</span> : null}
              </th>
              <td className="break-all py-2 pr-4 align-top text-neutral-100">{row.left}</td>
              <td className="break-all py-2 pr-4 align-top text-neutral-100">{row.right}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
