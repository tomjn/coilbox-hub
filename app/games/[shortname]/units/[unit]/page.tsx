import type { Metadata } from "next";
import { Suspense } from "react";
import Loading from "@/app/loading";
import { unitMetadata, UnitView } from "./UnitView";

type Params = Promise<{ shortname: string; unit: string }>;

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { shortname, unit } = await params;
  return unitMetadata(shortname, unit);
}

/** The route, for the latest facts. `params` is read behind a boundary of the
 *  page's own: the root loading file's boundary is already on screen when a
 *  visitor moves between two units, and would hold the old page up until the
 *  new one was ready. */
export default function Unit({ params }: { params: Params }) {
  return (
    <Suspense fallback={<Loading />}>
      <Latest params={params} />
    </Suspense>
  );
}

async function Latest({ params }: { params: Params }) {
  const { shortname, unit } = await params;
  return <UnitView shortname={shortname} unit={unit} />;
}
