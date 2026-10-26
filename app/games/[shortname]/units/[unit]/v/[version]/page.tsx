import type { Metadata } from "next";
import { Suspense } from "react";
import Loading from "@/app/loading";
import { unitMetadata, UnitView } from "../../UnitView";

type Params = Promise<{ shortname: string; unit: string; version: string }>;

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { shortname, unit, version } = await params;
  return unitMetadata(shortname, unit, decodeURIComponent(version));
}

/** One unit as a past release shipped it. `?v=` links from before this route
 *  existed are redirected here by `next.config.ts`. */
export default function UnitAtRelease({ params }: { params: Params }) {
  return (
    <Suspense fallback={<Loading />}>
      <AtRelease params={params} />
    </Suspense>
  );
}

async function AtRelease({ params }: { params: Params }) {
  const { shortname, unit, version } = await params;
  return <UnitView shortname={shortname} unit={unit} v={decodeURIComponent(version)} />;
}
