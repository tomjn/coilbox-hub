import type { Metadata } from "next";
import { parseFilters } from "@/lib/maps/query";
import { MapsView } from "./MapsView";

export const metadata: Metadata = {
  title: "Maps",
  description: "Browse the maps the hub holds facts about, by size, tag, author or name.",
};

/** Every map, unfiltered. A filtered listing is `filtered/page.tsx`. */
export default function Maps() {
  return <MapsView filters={parseFilters({})} />;
}
