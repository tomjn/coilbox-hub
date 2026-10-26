import type { Metadata } from "next";
import { kindsPlural } from "@/lib/gallery/label";
import { parseFilters } from "@/lib/gallery/query";
import { GalleryView } from "./GalleryView";

export const metadata: Metadata = {
  title: "Gallery",
  // Built from the kinds the chips offer, so it cannot say four when there are
  // five (tomjn/coilbox#1502).
  description: `${kindsPlural()} shared by other players.`,
};

/** The gallery, unfiltered. A filtered gallery is `filtered/page.tsx`. */
export default function Gallery() {
  return <GalleryView filters={parseFilters({})} />;
}
