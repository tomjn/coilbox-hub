import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { games } from "@/components/art/drawings";
import { Skeleton } from "@/components/Skeleton";

/** A profile's frame before it has been read. `app/gallery/loading.tsx` says why. */
export default function Loading() {
  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1" aria-busy="true">
      <ArtBackdrop drawing={games} strength={0.09} />
      <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-8 px-6 py-12">
        <p className="sr-only">Loading the profile</p>
        <Skeleton className="h-9 w-1/2" />
        {Array.from({ length: 4 }, (_, i) => (
          <Skeleton key={i} className="h-16" />
        ))}
      </div>
    </main>
  );
}
