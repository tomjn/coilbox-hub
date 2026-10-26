import { TreeView } from "./TreeView";

export { generateMetadata } from "./TreeView";

/** `/games/<game>/tree` with nothing chosen. It reads nothing from the request, so the CDN
 *  serves it. A request with a choice in its query is rewritten to
 *  `filtered/page.tsx` by `next.config.ts`. */
export default function TreePage({ params }: PageProps<"/games/[shortname]/tree">) {
  return <TreeView params={params} searchParams={Promise.resolve({})} />;
}
