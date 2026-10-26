import { ChangesView } from "../ChangesView";

export { generateMetadata } from "../ChangesView";

/** `/games/<game>/changes` with a choice in its query. `next.config.ts` rewrites the request
 *  here, so the page without one stays static. */
export default function FilteredReleaseChanges({ params, searchParams }: PageProps<"/games/[shortname]/changes/filtered">) {
  return <ChangesView params={params} searchParams={searchParams} />;
}
