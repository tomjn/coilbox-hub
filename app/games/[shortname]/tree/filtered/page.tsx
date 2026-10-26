import { TreeView } from "../TreeView";

export { generateMetadata } from "../TreeView";

/** `/games/<game>/tree` with a choice in its query. `next.config.ts` rewrites the request
 *  here, so the page without one stays static. */
export default function FilteredTreePage({ params, searchParams }: PageProps<"/games/[shortname]/tree/filtered">) {
  return <TreeView params={params} searchParams={searchParams} />;
}
