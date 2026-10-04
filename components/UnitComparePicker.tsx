import { Button } from "@/components/Button";
import { unitNameLabelsCached } from "@/lib/games/cached";

/**
 * Picks the second unit for a comparison (#466).
 *
 * A GET form with a text box and a `<datalist>`, so it works with no script and
 * the URL is the state. A game has hundreds of units, which is too long for a
 * `<select>`, and a datalist filters as the visitor types. Each option's value
 * is the unit's id, which is what `with` carries, and its label is the display
 * name. A visitor who types a display name instead is resolved on the server.
 */
export async function UnitComparePicker({
  game,
  unit,
  current,
  release,
  label = "Compare with another unit",
}: {
  game: string;
  unit: string;
  /** What the box starts with, so changing the second unit edits it. */
  current?: string;
  /** Kept through a change of unit, so the release stays where it was. */
  release?: string;
  label?: string;
}) {
  const labels = await unitNameLabelsCached(game);
  const options = [...labels.values()]
    .filter((entry) => entry.name !== unit)
    .sort((a, b) => a.label.localeCompare(b.label) || a.name.localeCompare(b.name));

  return (
    <form
      action={`/games/${game}/units/${encodeURIComponent(unit)}/compare`}
      method="get"
      className="flex flex-wrap items-end gap-3"
    >
      <div className="flex min-w-48 flex-1 flex-col gap-1.5">
        <label htmlFor="compare-with" className="text-xs uppercase tracking-wide text-neutral-400">
          {label}
        </label>
        <input
          id="compare-with"
          name="with"
          type="text"
          list="compare-with-units"
          defaultValue={current}
          autoComplete="off"
          placeholder="Unit name or id"
          className="w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
        />
        <datalist id="compare-with-units">
          {options.map((entry) => (
            <option key={entry.name} value={entry.name} label={entry.label} />
          ))}
        </datalist>
      </div>
      {release ? <input type="hidden" name="release" value={release} /> : null}
      <Button type="submit">Compare units</Button>
    </form>
  );
}
