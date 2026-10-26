import { ChangesView } from "./ChangesView";

export { generateMetadata } from "./ChangesView";

/** `/games/<game>/changes` with nothing chosen. It reads nothing from the request, so the CDN
 *  serves it. A request with a choice in its query is rewritten to
 *  `filtered/page.tsx` by `next.config.ts`. */
export default function ReleaseChanges({ params }: PageProps<"/games/[shortname]/changes">) {
  return <ChangesView params={params} searchParams={Promise.resolve({})} />;
}
