import type { Metadata } from "next";
import { parseFilters } from "@/lib/maps/query";
import { MapsView } from "../MapsView";

export const metadata: Metadata = {
  title: "Maps",
  description: "Browse the maps the hub holds facts about, by size, tag, author or name.",
};

/**
 * The listing with the filters in the query. `next.config.ts` rewrites a
 * request for `/maps` here when its query holds a filter, so the plain page
 * stays static and only a filtered listing runs a function.
 */
export default async function FilteredMaps({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  return <MapsView filters={parseFilters(await searchParams)} />;
}
