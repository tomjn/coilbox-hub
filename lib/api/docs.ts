import { GALLERY_KINDS } from "@/lib/container";
import { maxPage, PAGE_SIZE, SORT_ORDERS } from "@/lib/gallery/query";

/**
 * The query parameters of `GET /api/v1/items`, as the `/developers` page lists
 * them. `docs.test.ts` compares the names and the repeatable flags against the
 * ones `parseApiFilters` accepts, so a parameter added to the route without a
 * line here fails the tests.
 */
export interface ItemsParam {
  name: string;
  /** Whether the parameter may be sent more than once. */
  repeatable: boolean;
  type: string;
  default: string;
  description: string;
}

export const ITEMS_PARAMS: ItemsParam[] = [
  {
    name: "kind",
    repeatable: true,
    type: "string",
    default: "none, so every kind",
    description: `One of ${GALLERY_KINDS.join(", ")}. Send it more than once for any of several kinds. Any other value is a 400.`,
  },
  {
    name: "game",
    repeatable: false,
    type: "string",
    default: "none",
    description:
      "A game's shortname, as in the shortname field of /api/v1/games. Items that name their game only by an exact archive name do not match.",
  },
  {
    name: "map",
    repeatable: false,
    type: "string",
    default: "none",
    description: "A map name, matched exactly against the map_name field.",
  },
  {
    name: "tag",
    repeatable: true,
    type: "string",
    default: "none",
    description:
      "A tag. The hub lowercases it. Send it more than once for items that have any of the tags.",
  },
  {
    name: "author",
    repeatable: true,
    type: "string",
    default: "none",
    description:
      "An author name, matched exactly against the author_name field. Send it more than once for items by any of the authors.",
  },
  {
    name: "q",
    repeatable: false,
    type: "string",
    default: "none",
    description:
      "A search over item titles and descriptions. It takes what a person types into a search box, including quoted phrases and a minus sign to exclude a word.",
  },
  {
    name: "sort",
    repeatable: false,
    type: "string",
    default: SORT_ORDERS[0],
    description: `One of ${SORT_ORDERS.join(", ")}. Featured items come first in either order. Any other value is a 400.`,
  },
  {
    name: "page",
    repeatable: false,
    type: "integer",
    default: "1",
    description: `The page to return, counting from 1, with ${PAGE_SIZE} items to a page. A value that is not a whole number above zero is read as 1. A page above ${maxPage(PAGE_SIZE)} is a 400.`,
  },
];
