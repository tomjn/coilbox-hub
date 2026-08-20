/**
 * A block the shape of what is about to arrive.
 *
 * Used by the `loading.tsx` files, which the router swaps in the moment a link
 * is tapped and before the page's data has been read. Pulses unless the reader
 * has asked for less motion, in which case it sits still. Hidden from assistive
 * technology: the `<main aria-busy>` around it already says the page is loading,
 * and a dozen empty boxes would only be noise to read out.
 */
export function Skeleton({ className }: { className: string }) {
  return (
    <div
      aria-hidden
      className={`animate-pulse rounded-md bg-neutral-900 motion-reduce:animate-none ${className}`}
    />
  );
}
