import { UnitsView } from "./UnitsView";

export { generateMetadata } from "./UnitsView";

/** `/games/<game>/units` with nothing chosen. It reads nothing from the request, so the CDN
 *  serves it. A request with a choice in its query is rewritten to
 *  `../units-filtered/page.tsx` by `next.config.ts`. */
export default function Units({ params }: PageProps<"/games/[shortname]/units">) {
  return <UnitsView params={params} searchParams={Promise.resolve({})} />;
}
