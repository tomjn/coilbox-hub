import { Skeleton } from "@/components/Skeleton";

/**
 * An item page's frame before the item has been read. No backdrop: the drawing
 * behind an item depends on its kind, which is not known yet, and a drawing
 * that changed once the page arrived would be worse than one that fades in.
 * `app/gallery/loading.tsx` says why the rest is here.
 */
export default function Loading() {
  return (
    <main className="relative flex-1" aria-busy="true">
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <p className="sr-only">Loading the item</p>
        <div className="flex flex-col gap-3">
          <Skeleton className="h-6 w-24" />
          <Skeleton className="h-9 w-2/3" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-5/6" />
        </div>
        <Skeleton className="h-64" />
        <Skeleton className="h-28" />
      </div>
    </main>
  );
}
