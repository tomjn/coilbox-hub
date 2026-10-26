import type { Metadata } from "next";
import { Suspense } from "react";
import NotFound from "../../not-found";
import { WithdrawnHint } from "./WithdrawnHint";

export const metadata: Metadata = {
  title: "Page not found",
};

/** The site's not found page, plus a pointer for the two people who can still
 *  see a withdrawn item. */
export default function ItemNotFound() {
  return (
    <>
      <NotFound />
      <Suspense>
        <WithdrawnHint />
      </Suspense>
    </>
  );
}
