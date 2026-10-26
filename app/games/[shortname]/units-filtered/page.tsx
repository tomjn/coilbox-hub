import { UnitsView } from "../units/UnitsView";

export { generateMetadata } from "../units/UnitsView";

/** `/games/<game>/units` with a choice in its query. `next.config.ts` rewrites the request
 *  here, so the page without one stays static. */
export default function FilteredUnits({ params, searchParams }: PageProps<"/games/[shortname]/units-filtered">) {
  return <UnitsView params={params} searchParams={searchParams} />;
}
