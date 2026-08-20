import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { skirmish } from "@/components/art/drawings";
import { Skeleton } from "@/components/Skeleton";

/** The same column the page sets its words in. */
const COLUMN = "mx-auto w-full max-w-3xl px-6";

/**
 * A map page's frame before the map has been read: the real backdrop at the
 * page's own strength, a title, a square where the figure will be and a row
 * where the facts will be. `app/gallery/loading.tsx` says why.
 */
export default function Loading() {
  return (
    <main className="relative flex-1" aria-busy="true">
      <ArtBackdrop drawing={skirmish} strength={0.05} />
      <div className="relative z-10 flex w-full flex-col gap-8 py-12">
        <p className="sr-only">Loading the map</p>
        <div className={`${COLUMN} flex flex-col gap-3`}>
          <Skeleton className="h-9 w-2/3" />
          <Skeleton className="h-4 w-1/3" />
        </div>
        <div className={COLUMN}>
          <Skeleton className="mx-auto aspect-square w-full max-w-lg" />
        </div>
        <div className={`${COLUMN} grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4`}>
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-12" />
          ))}
        </div>
      </div>
    </main>
  );
}
