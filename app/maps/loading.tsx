import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { skirmish } from "@/components/art/drawings";
import { Skeleton } from "@/components/Skeleton";

/**
 * The catalog's frame before its maps have been read: the real heading, the
 * real backdrop at the page's own strength, and boxes where the filters and the
 * first two rows of cards will be. `app/gallery/loading.tsx` says why.
 */
export default function Loading() {
  return (
    <main className="relative flex-1" aria-busy="true">
      <ArtBackdrop drawing={skirmish} strength={0.05} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Maps</h1>
          <p className="text-neutral-400">
            What the hub knows about the maps people play on, from the archives
            themselves.
          </p>
        </div>
        <p className="sr-only">Loading the maps</p>

        <div className="grid gap-4 border-b border-neutral-900 pb-6 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>

        <ul className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <li key={i} className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-4">
              <Skeleton className="aspect-square w-full" />
              <Skeleton className="h-4 w-3/4" />
              <Skeleton className="h-3 w-1/2" />
            </li>
          ))}
        </ul>
      </div>
    </main>
  );
}
