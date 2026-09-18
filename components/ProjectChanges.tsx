import Link from "next/link";
import type { ServedAsset } from "@/lib/assets/resolve";
import {
  CHANGELOG_ROW_LIMIT,
  describeProject,
  modProjectChangelog,
  modProjectCounts,
  modProjectDetails,
  type ProjectMenuOp,
  type ProjectUnitChange,
  type ProjectUnitDetail,
} from "@/lib/gallery/modProjectPreview";
import type { UnitNameLink } from "./ItemPreview";

type Names = ReadonlyMap<string, UnitNameLink>;

/**
 * A unit by the name the catalog gives it, linked to its encyclopedia page,
 * with the game's own internal name beside it. A project's edits are keyed on
 * the internal name, so a reader checking one against their own game needs it
 * on screen even where the catalog has a nicer one. A unit the catalog does
 * not know, which every unit a project adds is, keeps the raw key alone.
 */
function UnitName({ def, names }: { def: string; names: Names }) {
  const known = names.get(def.toLowerCase());
  if (!known) return <span className="break-all">{def}</span>;
  return (
    <>
      <Link href={known.href} className="hover:text-white active:text-white">
        {known.label}
      </Link>{" "}
      <span className="break-all text-neutral-500">({def})</span>
    </>
  );
}

function MenuOp({ op, names }: { op: ProjectMenuOp; names: Names }) {
  const unit = <UnitName def={op.unit} names={names} />;
  if (op.op !== "move") {
    return op.op === "add" ? (
      <>Adds {unit} to its build menu</>
    ) : (
      <>Takes {unit} off its build menu</>
    );
  }
  return op.before === null ? (
    <>Moves {unit} to the end of its build menu</>
  ) : (
    <>
      Moves {unit} before <UnitName def={op.before} names={names} />
    </>
  );
}

/** What happened to the unit as a whole, as opposed to the values under it. */
function headline(change: ProjectUnitChange, names: Names) {
  if (change.disabled) return "Switched off. Nothing can build it.";
  if (!change.added) return null;
  return change.source ? (
    <>
      A new unit, copied from <UnitName def={change.source} names={names} />
    </>
  ) : (
    "A new unit"
  );
}

function UnitRow({
  change,
  detail,
  picture,
  pictured,
  names,
}: {
  change: ProjectUnitChange;
  detail: ProjectUnitDetail | undefined;
  picture: ServedAsset | undefined;
  /** Whether any row in the list has a picture. */
  pictured: boolean;
  names: Names;
}) {
  const about = headline(change, names);

  return (
    <li className="flex gap-4 py-4">
      {/* The square stays when only this unit has no picture, so the names in a
          list still line up. With no pictures at all there is no column. */}
      <div
        className={
          pictured
            ? "size-12 shrink-0 overflow-hidden rounded border border-neutral-800 bg-black"
            : "hidden"
        }
      >
        {picture ? (
          // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image, see next.config.ts
          <img
            src={picture.url}
            alt=""
            width={picture.width}
            height={picture.height}
            decoding="async"
            loading="lazy"
            className={`size-full object-cover ${change.disabled ? "opacity-40 grayscale" : ""}`}
          />
        ) : null}
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <h3 className="text-sm font-medium text-neutral-100">
          <UnitName def={change.unit} names={names} />
        </h3>
        {about ? <p className="text-sm text-neutral-400">{about}</p> : null}

        {detail && detail.fields.length > 0 ? (
          <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-1 font-mono text-xs">
            {detail.fields.map(([path, value]) => (
              <div key={path} className="contents">
                <dt className="break-all text-neutral-400">{path}</dt>
                <dd className="whitespace-pre-wrap break-all text-neutral-100">{value}</dd>
              </div>
            ))}
          </dl>
        ) : null}

        {detail && detail.text.length > 0 ? (
          <ul className="flex flex-col gap-1 text-sm text-neutral-400">
            {detail.text.map(([language, field, words]) => (
              <li key={`${language}.${field}`}>
                {field === "name" ? "Renamed" : "Described as"}{" "}
                <q className="text-neutral-100">{words}</q>{" "}
                <span className="text-neutral-500">({language})</span>
              </li>
            ))}
          </ul>
        ) : null}

        {detail && detail.menu.length > 0 ? (
          <ol className="flex flex-col gap-1 text-sm text-neutral-400">
            {detail.menu.map((op, i) => (
              <li key={i}>
                <MenuOp op={op} names={names} />
              </li>
            ))}
          </ol>
        ) : null}
      </div>
    </li>
  );
}

/**
 * What a project does, unit by unit (issues #324 and #418).
 *
 * One sentence for the scale of it, then a row per unit with the values
 * themselves: the published payload carries every edit in full, and a reader
 * deciding whether to play with a rebalance wants to see that armour went to
 * 5000, not that one field changed.
 *
 * Capped at {@link CHANGELOG_ROW_LIMIT}: a full roster rebalance can touch
 * every unit a game has. The remainder is said as a count, never dropped
 * silently.
 */
export function ProjectChanges({
  payload,
  units,
  names,
}: {
  payload: Record<string, unknown>;
  units: ReadonlyMap<string, ServedAsset>;
  names: Names;
}) {
  const changes = modProjectChangelog(payload);
  if (changes.length === 0) return null;
  const details = modProjectDetails(payload);
  const shown = changes.slice(0, CHANGELOG_ROW_LIMIT);
  const hidden = changes.length - shown.length;
  const pictured = shown.some((change) => units.has(change.unit));

  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-lg font-semibold tracking-tight">What it changes</h2>
      <p className="text-sm text-neutral-400">{describeProject(modProjectCounts(payload))}</p>
      <ul className="flex flex-col divide-y divide-neutral-900">
        {shown.map((change) => (
          <UnitRow
            key={change.unit}
            change={change}
            detail={details.get(change.unit)}
            picture={units.get(change.unit)}
            pictured={pictured}
            names={names}
          />
        ))}
      </ul>
      {hidden > 0 ? (
        <p className="text-sm text-neutral-500">
          and {hidden} more unit{hidden === 1 ? "" : "s"}
        </p>
      ) : null}
    </section>
  );
}
