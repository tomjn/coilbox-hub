import type { Metadata } from "next";
import { Suspense } from "react";
import Loading from "@/app/loading";
import { readUnitSegment } from "@/lib/games/unitRelease";
import { unitMetadata, UnitView } from "./UnitView";

type Params = Promise<{ shortname: string; unit: string }>;

/** One placeholder, which renders as not found. On Vercel an unlisted page is
 *  upgraded to a whole static page after its first visit only when its segment
 *  has a `generateStaticParams`, and Cache Components refuses an empty list. */
export function generateStaticParams() {
  return [{ unit: "__none__" }];
}

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { shortname, unit: segment } = await params;
  const { unit, release } = readUnitSegment(segment);
  return unitMetadata(shortname, unit, release);
}

/** The route, for the latest facts or for `<unit>@<release>`
 *  (`lib/games/unitRelease.ts`). `params` is read behind a boundary of the
 *  page's own: the root loading file's boundary is already on screen when a
 *  visitor moves between two units, and would hold the old page up until the
 *  new one was ready. */
export default function Unit({ params }: { params: Params }) {
  return (
    <Suspense fallback={<Loading />}>
      <Shown params={params} />
    </Suspense>
  );
}

async function Shown({ params }: { params: Params }) {
  const { shortname, unit: segment } = await params;
  const { unit, release } = readUnitSegment(segment);
  return <UnitView shortname={shortname} unit={unit} v={release} />;
}
