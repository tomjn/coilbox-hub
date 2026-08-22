/**
 * What a unit's stats say, in the words a page prints (#227).
 *
 * The stats blob is whatever the extraction measured, schemaless by design: the
 * vocabulary lives in the client that produces it, and the hub refusing an
 * unknown key would mean every extraction improvement waited on a deploy. So
 * nothing here can know the whole vocabulary. What it can do is print the keys
 * it has been told about in the words players use, and print everything else
 * honestly rather than not at all.
 */

/**
 * The keys with names of their own, in reading order.
 *
 * A player asks what it costs and how hard it dies before anything else, so
 * those lead. Everything not listed here follows alphabetically, which keeps
 * the table stable as extractions grow new keys.
 */
const KNOWN: { key: string; label: string }[] = [
  { key: "health", label: "Health" },
  { key: "metalCost", label: "Metal cost" },
  { key: "energyCost", label: "Energy cost" },
  { key: "buildTime", label: "Build time" },
  { key: "sightDistance", label: "Sight range" },
  { key: "maxVelocity", label: "Top speed" },
];

const LABELS = new Map(KNOWN.map((entry) => [entry.key, entry.label]));

/** The name a stat is printed under. An unknown key prints as itself, because
 *  inventing a name would be worse than printing the one it arrived with. */
export function statLabel(key: string): string {
  return LABELS.get(key) ?? key;
}

/** One row of the table: the key in reading order, then everything else
 *  alphabetically. */
export function statRows(stats: Record<string, unknown>): { key: string; label: string }[] {
  const known = KNOWN.filter((entry) => entry.key in stats).map((entry) => ({
    key: entry.key,
    label: entry.label,
  }));
  const rest = Object.keys(stats)
    .filter((key) => !LABELS.has(key))
    .sort()
    .map((key) => ({ key, label: statLabel(key) }));
  return [...known, ...rest];
}

/**
 * One value as a cell prints it.
 *
 * Absent stays absent: a def that declares no health reads as a dash, because
 * zero would claim the unit cannot take a hit. Arrays of flat records are the
 * shape a weapons list arrives in (#261), and those belong in a table rather
 * than as JSON; everything else arrives as compact JSON - data, not prose, so
 * the hub is not guessing at a vocabulary it does not hold.
 */
export function formatStatValue(value: unknown): string {
  if (value === null || value === undefined) return "-";
  if (typeof value === "string") return value;
  if (typeof value === "number" || typeof value === "boolean") return String(value);
  return JSON.stringify(value);
}

/**
 * The rows of a tabular stat value, or null when one does not apply.
 *
 * A weapons summary is an array of records - `{range, damage, reload,
 * projectile}`, one per weapon. Anything else (scalars, arrays of scalars,
 * mixed shapes) stays with `formatStatValue`, because a table drawn over it
 * would be inventing structure the extraction did not state.
 */
export function tabularStatRows(value: unknown): Record<string, unknown>[] | null {
  if (!Array.isArray(value) || value.length === 0) return null;
  if (
    !value.every(
      (item) => typeof item === "object" && item !== null && !Array.isArray(item),
    )
  ) {
    return null;
  }
  return value as Record<string, unknown>[];
}

/** Every column these rows carry, in first-appearance order. A weapon lacking
 *  a column the others have leaves its cell empty rather than dropping the
 *  column, since the others do carry it. */
export function tabularColumns(rows: Record<string, unknown>[]): string[] {
  const columns: string[] = [];
  for (const row of rows) {
    for (const key of Object.keys(row)) {
      if (!columns.includes(key)) columns.push(key);
    }
  }
  return columns;
}
