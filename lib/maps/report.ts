/**
 * Reporting a broken map (#294).
 *
 * The kinds are the list `report_kind_check` holds
 * (`20261018220000_report_map.sql`). The two have to agree, because a kind the
 * database does not know is a report that is refused.
 */
export const MAP_REPORT_KINDS = [
  { value: "crashes_engine", label: "Crashes the engine" },
  { value: "files_missing", label: "Files are missing or corrupt" },
  { value: "download_wrong", label: "The download is wrong or dead" },
  { value: "wrong_map", label: "It is not the map it says it is" },
  { value: "other", label: "Something else" },
] as const;

export type MapReportKind = (typeof MAP_REPORT_KINDS)[number]["value"];

export function mapReportKindLabel(kind: string | null): string {
  return MAP_REPORT_KINDS.find((k) => k.value === kind)?.label ?? "Unknown kind";
}

export function isMapReportKind(value: unknown): value is MapReportKind {
  return MAP_REPORT_KINDS.some((k) => k.value === value);
}

/** The words the report form shows, in the shape `useActionState` hands back. */
export interface MapReportState {
  ok: boolean;
  message: string;
}

export const MAP_REPORT_MESSAGES = {
  sent: "Reported. Thank you.",
  noKind: "Choose what is wrong with the map.",
  noReason: "Say what is wrong, in a sentence or two.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
  notSaved: "That could not be sent. Try again in a few minutes.",
} as const;
