import catalog from "./vendor/map-catalog.json";

/**
 * The request caps, read out of the vendored catalog rather than restated in hub
 * code (#185).
 *
 * These numbers are an agreement between two repositories, not a hub
 * preference. A client splits its work on the number it holds, the hub refuses
 * anything over the number it holds, and the two being the same number is the
 * whole reason the file is vendored rather than written out twice. A fresh `500`
 * beside a file that already says 500 is a second copy of the agreement, and it
 * would part company quietly: the client would go on sending batches the hub had
 * started refusing, and the only symptom is a 413 for a request the client was
 * told to make.
 *
 * The caps and nothing else, because the caps are all the hub reads. The fact
 * list, the point kinds and the clustering parameters describe what a client
 * extracts, and the hub never follows them - `lib/api/auth.ts` sets out why it
 * serves the catalog's digest rather than its contents, and an accessor for the
 * rest of the file would be an invitation to do the thing that comment refuses.
 */
export const MAP_CATALOG_CAPS = catalog.caps;
