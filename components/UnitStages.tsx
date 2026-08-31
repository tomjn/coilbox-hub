import Link from "next/link";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { formatStatValue, statLabel } from "@/lib/games/stats";
import type { StageStatRow, UnitStage } from "@/lib/games/units";

/**
 * The stages of one unit's life (#295).
 *
 * A commander that upgrades through tech levels is one unit at five stages,
 * and the grid shows one cell for it. This is where the levels are laid out:
 * in order, each linking to its own page, each saying what it costs to reach
 * and what it unlocks. The stage being shown is marked rather than made
 * unclickable, so a reader can always see where they are.
 *
 * Conditions print as the game spelled them. Four games spell a morph's cost
 * four ways, and naming them here would mean refusing the fifth.
 */
export function StageStrip({
  game,
  stages,
  pictures,
}: {
  game: string;
  stages: UnitStage[];
  pictures: ReadonlyMap<string, ResolvedAsset>;
}) {
  // The stage before this one, by the name a reader sees rather than by the
  // def key. "Reached from armcom1" beside a heading saying Commander reads as
  // a different unit entirely.
  const nameOf = new Map(stages.map((stage) => [stage.unit_name, stage.label]));

  return (
    <ol className="flex flex-col gap-2">
      {stages.map((stage, index) => {
        const picture = pictures.get(stage.unit_name);
        const conditions = Object.entries(stage.conditions);
        const previous = stage.from ? (nameOf.get(stage.from) ?? stage.from) : null;
        return (
          <li
            key={stage.unit_name}
            className={`flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-start sm:gap-4 ${
              stage.current
                ? "border-neutral-600 bg-neutral-900"
                : "border-neutral-900 bg-neutral-950"
            }`}
          >
            {picture && picture.from !== "placeholder" ? (
              // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
              <img
                src={picture.url}
                alt=""
                width={picture.width}
                height={picture.height}
                loading="lazy"
                decoding="async"
                className="size-12 shrink-0 object-contain"
              />
            ) : null}

            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
              <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="text-xs text-neutral-500">Stage {index + 1}</span>
                <Link
                  href={`/games/${game}/units/${stage.unit_name}`}
                  className="text-sm text-neutral-100 underline-offset-4 hover:underline active:underline"
                >
                  {stage.label}
                </Link>
                {stage.current ? (
                  <span className="text-xs text-neutral-400">showing this one</span>
                ) : null}
                {stage.removed_at ? (
                  <span className="text-xs text-neutral-500">retired</span>
                ) : null}
                {!stage.found ? (
                  <span className="text-xs text-neutral-500">not in this release</span>
                ) : null}
              </div>

              {previous && conditions.length > 0 ? (
                <p className="text-sm text-neutral-400">
                  Reached from {previous} for{" "}
                  {conditions
                    .map(([name, value]) => `${statLabel(name)} ${formatStatValue(value)}`)
                    .join(", ")}
                  .
                </p>
              ) : previous ? (
                <p className="text-sm text-neutral-400">
                  Reached from {previous}, on terms the extraction did not report.
                </p>
              ) : null}

              {stage.unlocks.length > 0 ? (
                <p className="text-sm text-neutral-400">
                  Unlocks{" "}
                  {stage.unlocks.map((unit, position) => (
                    <span key={unit.name}>
                      {position > 0 ? ", " : ""}
                      <Link
                        href={`/games/${game}/units/${unit.name}`}
                        className="text-neutral-300 underline-offset-4 hover:underline active:underline"
                      >
                        {unit.label}
                      </Link>
                    </span>
                  ))}
                  .
                </p>
              ) : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * The stats read across the stages, so what an upgrade costs has something to
 * be weighed against.
 *
 * A row whose value is the same at every stage prints quiet, because a reader
 * comparing levels is looking for what moves. Nothing is dropped for being
 * still: a stat that does not change under an upgrade is itself worth knowing.
 */
export function StageStats({
  stages,
  rows,
}: {
  stages: UnitStage[];
  rows: StageStatRow[];
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-max text-sm">
        <thead>
          <tr>
            <th
              scope="col"
              className="border-b border-neutral-800 px-2 py-1 text-left font-normal text-neutral-400"
            >
              Stat
            </th>
            {stages.map((stage) => (
              <th
                key={stage.unit_name}
                scope="col"
                className={`border-b border-neutral-800 px-2 py-1 text-left font-normal ${
                  stage.current ? "text-neutral-100" : "text-neutral-400"
                }`}
              >
                {stage.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key}>
              <th scope="row" className="px-2 py-1 text-left font-normal text-neutral-400">
                {row.label}
              </th>
              {row.values.map((value, index) => (
                <td
                  key={stages[index].unit_name}
                  className={
                    row.changed ? "px-2 py-1 text-neutral-100" : "px-2 py-1 text-neutral-500"
                  }
                >
                  {value}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
