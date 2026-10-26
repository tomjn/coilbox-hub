/**
 * The query keys each listing reads, for `next.config.ts`.
 *
 * A listing's own page reads nothing from the request, so the CDN serves it. A
 * request whose query holds one of these keys is rewritten to the listing's
 * `filtered` page, which reads them. A key missing from here is ignored, so
 * each list is tested against the links its page builds.
 *
 * No imports, because `next.config.ts` loads this before the app is built.
 */
export const MAP_FILTER_KEYS = ["q", "tag", "author", "size", "players", "sort", "page"] as const;
