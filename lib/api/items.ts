import { GALLERY_KINDS } from "@/lib/container";
import {
  type Filters,
  type ItemSummary,
  PAGE_SIZE,
  parseFilters,
  SORT_ORDERS,
} from "@/lib/gallery/query";

/**
 * Each shape this API hands out carries its own `format` and `version`, the
 * way `/export` already does. A shipped desktop build sits on disk for months,
 * so a client reads these two fields before touching anything else and can
 * say "this service is newer than I understand" instead of guessing at a
 * shape that changed under it.
 */
export const ITEMS_FORMAT = "coilbox-hub-items";
export const ITEMS_VERSION = 1;
export const ITEM_FORMAT = "coilbox-hub-item";
export const ITEM_VERSION = 1;

export interface ItemsListBody {
  format: typeof ITEMS_FORMAT;
  version: typeof ITEMS_VERSION;
  page: number;
  page_size: number;
  total: number;
  items: ItemSummary[];
}

export function buildItemsListBody(
  items: ItemSummary[],
  page: number,
  total: number,
): ItemsListBody {
  return {
    format: ITEMS_FORMAT,
    version: ITEMS_VERSION,
    page,
    page_size: PAGE_SIZE,
    total,
    items,
  };
}

/** The summary plus a pointer at the container rather than the container
 * itself. `/i/<id>` already exists, is what coilbox's import link already
 * targets, and carries its own short cache lifetime for takedowns. Inlining
 * the container here would duplicate the largest column on the row into
 * every browse-then-view round trip and give it a second, easily
 * out-of-step cache policy. */
export interface ApiItem extends ItemSummary {
  container_url: string;
}

export interface ItemBody {
  format: typeof ITEM_FORMAT;
  version: typeof ITEM_VERSION;
  item: ApiItem;
}

export function buildItemBody(item: ItemSummary, containerUrl: string): ItemBody {
  return {
    format: ITEM_FORMAT,
    version: ITEM_VERSION,
    item: { ...item, container_url: containerUrl },
  };
}

const FILTER_KEYS = [
  "kind",
  "game",
  "map",
  "tag",
  "author",
  "q",
  "sort",
  "page",
] as const;

/** `kind`, `tag` and `author` take more than one value, by repeating the
 *  parameter (`?kind=preset&kind=blueprint`). Everything else takes exactly
 *  one: a second `game` or `page` is as much a client bug as an unknown
 *  parameter name, and the same silent-take-one failure this file exists to
 *  rule out for `kind`, so it is rejected the same way. */
const SINGLE_VALUE_KEYS = ["game", "map", "q", "sort", "page"] as const;

export type ParsedApiFilters =
  | { ok: true; filters: Filters }
  | { ok: false; error: string };

/**
 * `parseFilters` is deliberately forgiving: a typo in a shared gallery link
 * should fall back to an unfiltered view rather than an error page. A client
 * asking this API for a filter it thinks it applied needs the opposite. If
 * `kind=challeng` gets silently dropped, the API hands back everything and
 * the client renders it believing it is looking at only challenges. So here,
 * unknown parameter names, a repeated single-value parameter, an unrecognised
 * `kind` and an unrecognised `sort` are all rejected outright rather than
 * dropped or silently narrowed to one value.
 */
export function parseApiFilters(searchParams: URLSearchParams): ParsedApiFilters {
  for (const key of searchParams.keys()) {
    if (!(FILTER_KEYS as readonly string[]).includes(key)) {
      return { ok: false, error: `Unknown query parameter: ${key}` };
    }
  }

  for (const key of SINGLE_VALUE_KEYS) {
    if (searchParams.getAll(key).length > 1) {
      return { ok: false, error: `${key} takes one value, not several. Send it once.` };
    }
  }

  for (const kind of searchParams.getAll("kind")) {
    if (!(GALLERY_KINDS as readonly string[]).includes(kind)) {
      const hint = kind.includes(",")
        ? ". Repeat kind for more than one, e.g. kind=preset&kind=blueprint."
        : "";
      return { ok: false, error: `Unknown kind: ${kind}${hint}` };
    }
  }

  const sort = searchParams.get("sort");
  if (sort && !(SORT_ORDERS as readonly string[]).includes(sort)) {
    return { ok: false, error: `Unknown sort: ${sort}` };
  }

  const params: Record<string, string | string[]> = {};
  for (const key of new Set(searchParams.keys())) {
    const values = searchParams.getAll(key);
    params[key] = values.length > 1 ? values : (values[0] ?? "");
  }

  return { ok: true, filters: parseFilters(params) };
}
