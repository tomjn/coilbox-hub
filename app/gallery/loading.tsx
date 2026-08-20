import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { hub } from "@/components/art/drawings";
import { Skeleton } from "@/components/Skeleton";

/**
 * What the gallery looks like before its rows have been read.
 *
 * The router shows this the moment a link to the gallery is tapped and replaces
 * it once the page arrives, so the frame, the backdrop and the words that never
 * change are the real ones and only the rows are stand-ins. The same strength
 * as the page's own backdrop, so nothing shifts when the page takes over.
 */
export default function Loading() {
  return (
    <main className="relative flex-1" aria-busy="true">
      <ArtBackdrop drawing={hub} strength={0.07} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Gallery</h1>
          <p className="text-neutral-400">
            Made by other players. Importing needs no account.
          </p>
        </div>
        <p className="sr-only">Loading the gallery</p>

        <Skeleton className="h-10 w-full" />

        <div className="flex flex-wrap items-baseline gap-2 border-b border-neutral-900 pb-6">
          <Skeleton className="h-4 w-12" />
          {Array.from({ length: 5 }, (_, i) => (
            <Skeleton key={i} className="h-6 w-20 rounded-full" />
          ))}
        </div>

        <ul className="grid gap-4 sm:grid-cols-2">
          {Array.from({ length: 6 }, (_, i) => (
            <li key={i}>
              <Skeleton className="h-40" />
            </li>
          ))}
        </ul>
      </div>
    </main>
  );
}
