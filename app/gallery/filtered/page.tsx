import type { Metadata } from "next";
import { kindsPlural } from "@/lib/gallery/label";
import { parseFilters } from "@/lib/gallery/query";
import { GalleryView } from "../GalleryView";

export const metadata: Metadata = {
  title: "Gallery",
  // Built from the kinds the chips offer, so it cannot say four when there are
  // five (tomjn/coilbox#1502).
  description: `${kindsPlural()} shared by other players.`,
};

/**
 * The gallery with the filters in the query. `next.config.ts` rewrites a
 * request for `/gallery` here when its query holds a filter, so the plain page
 * stays static and only a filtered gallery runs a function.
 */
export default async function FilteredGallery({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  return <GalleryView filters={parseFilters(await searchParams)} />;
}
