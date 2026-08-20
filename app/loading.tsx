import { Skeleton } from "@/components/Skeleton";

/**
 * The frame every route without a loading file of its own shows while it is
 * read: publish, account and the moderation pages. The four pages a visitor
 * reaches most have a closer likeness of themselves beside them. This one is
 * shaped like a page of text, which is what the rest are.
 */
export default function Loading() {
  return (
    <main
      className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-6 py-12"
      aria-busy="true"
    >
      <p className="sr-only">Loading</p>
      <Skeleton className="h-9 w-1/3" />
      <Skeleton className="h-4 w-full" />
      <Skeleton className="h-4 w-5/6" />
      <Skeleton className="h-4 w-2/3" />
    </main>
  );
}
