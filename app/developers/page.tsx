import type { Metadata } from "next";
import type { ReactNode } from "react";
import { ITEMS_PARAMS } from "@/lib/api/docs";
import { AUTH_FORMAT, AUTH_VERSION, type AuthBody } from "@/lib/api/auth";
import { GAME_LIST_FORMAT, GAME_LIST_VERSION, type GameListResponseBody } from "@/lib/api/gameList";
import { buildItemBody, buildItemsListBody } from "@/lib/api/items";
import {
  MAP_PACK_LIST_FORMAT,
  MAP_PACK_LIST_VERSION,
  type MapPackListResponseBody,
} from "@/lib/api/mapPackList";
import { GALLERY_KINDS } from "@/lib/container";
import { DOWNLOAD_KINDS } from "@/lib/games/download";
import { FEED_ENTRIES } from "@/lib/feed/gallery";
import { PAGE_SIZE, type ItemSummary } from "@/lib/gallery/query";
import { siteUrl } from "@/lib/site";

export const metadata: Metadata = {
  title: "API",
  description: "How to read the gallery, games and map packs of Coilbox Hub from your own program.",
};

const ENDPOINTS = [
  { id: "items", label: "List items", path: "GET /api/v1/items" },
  { id: "item", label: "Get one item", path: "GET /api/v1/items/[id]" },
  { id: "games", label: "List games", path: "GET /api/v1/games" },
  { id: "map-packs", label: "List map packs", path: "GET /api/v1/map-packs" },
  { id: "auth", label: "Sign in configuration", path: "GET /api/v1/auth" },
  { id: "container", label: "Get an item's container", path: "GET /i/[id]" },
  { id: "export", label: "Export the gallery", path: "GET /export" },
  { id: "feed", label: "Atom feed", path: "GET /feed.xml" },
] as const;

/* Placeholder values for the examples. The ids and the author are the ones the
   local seed data uses, which belong to nobody. */
const FIRST_ID = "0f8fad5b-0449-4000-8000-0000000000a1";
const SECOND_ID = "0f8fad5b-0449-4000-8000-0000000000a2";

const FIRST_ITEM: ItemSummary = {
  id: FIRST_ID,
  kind: "preset",
  mode: null,
  title: "Seed item, just published",
  description: "Seeded for local checking.",
  game_name: "Tab Test Game",
  game_key: null,
  map_name: "Seed Map",
  tags: ["seed"],
  author_name: "Seed Author",
  created_at: "2026-10-04T01:34:37.214+00:00",
  featured_at: null,
};

const SECOND_ITEM: ItemSummary = {
  ...FIRST_ITEM,
  id: SECOND_ID,
  title: "Seed item, months old",
  created_at: "2026-05-07T01:39:37.214+00:00",
};

const GAMES_EXAMPLE: GameListResponseBody = {
  format: GAME_LIST_FORMAT,
  version: GAME_LIST_VERSION,
  games: [
    {
      shortname: "tabtest",
      title: "Tab Test Game",
      description: null,
      featured: false,
      downloads: [],
      logo: null,
      card: null,
      faction_count: 2,
      unit_count: 12,
      item_count: 0,
    },
  ],
};

/* Constructed, not captured: the local database holds no map packs. */
const MAP_PACKS_EXAMPLE: MapPackListResponseBody = {
  format: MAP_PACK_LIST_FORMAT,
  version: MAP_PACK_LIST_VERSION,
  packs: [
    {
      id: "00000000-0000-4000-8000-000000000001",
      title: "Example pack",
      blurb: "A short line about the pack.",
      featured: true,
      maps: [
        {
          id: "Example Map 1.0",
          title: "Example Map",
          download: { kind: "map", springName: "Example Map 1.0" },
          filename: "example_map_1.0.sd7",
        },
      ],
    },
  ],
};

/* The two digests are the real ones. The address and the key are placeholders,
   since a deployment's own values are what the route returns. */
const AUTH_EXAMPLE: AuthBody = {
  format: AUTH_FORMAT,
  version: AUTH_VERSION,
  supabase_url: "https://PROJECT.supabase.co",
  publishable_key: "sb_publishable_PLACEHOLDER",
  asset_vocabulary: "sha256:4879f85b17a5cee05e71b04fbe44607ba2cfb957bef0b2ac7c3dd658519d8b4e",
  map_catalog: "sha256:f013ff255fef10683673599df2d513e53eab69dddc87c79622ae1d207f611b20",
};

const json = (value: unknown) => JSON.stringify(value, null, 2);

function Code({ children, label }: { children: string; label: string }) {
  return (
    <pre
      tabIndex={0}
      aria-label={label}
      className="overflow-x-auto rounded border border-neutral-800 bg-black p-3 text-xs leading-relaxed text-neutral-100"
    >
      <code className="font-mono">{children}</code>
    </pre>
  );
}

function Inline({ children }: { children: ReactNode }) {
  return <code className="font-mono text-[0.9em] text-neutral-100">{children}</code>;
}

/* A name, its type and what it means, one to a block so the list reads on a
   phone without a table to scroll. */
function Fields({ items }: { items: { name: string; type: string; text: ReactNode }[] }) {
  return (
    <dl className="flex flex-col gap-3 text-sm">
      {items.map((field) => (
        <div key={field.name} className="flex flex-col gap-0.5">
          <dt className="font-mono text-neutral-100">
            {field.name} <span className="text-neutral-400">{field.type}</span>
          </dt>
          <dd className="text-neutral-300">{field.text}</dd>
        </div>
      ))}
    </dl>
  );
}

function Errors({ rows }: { rows: { status: number; body: string; text: string }[] }) {
  return (
    <ul className="flex flex-col gap-2 text-sm text-neutral-300">
      {rows.map((row) => (
        <li key={`${row.status}${row.body}`}>
          <Inline>{row.status}</Inline> {row.text} Body: <Inline>{row.body}</Inline>
        </li>
      ))}
    </ul>
  );
}

function Section({
  id,
  title,
  path,
  children,
}: {
  id: string;
  title: string;
  path?: string;
  children: ReactNode;
}) {
  return (
    <section id={id} aria-labelledby={`${id}-heading`} className="flex flex-col gap-4 scroll-mt-6">
      <h2 id={`${id}-heading`} className="text-xl font-semibold">
        {title}
      </h2>
      {path ? <p className="break-all font-mono text-sm text-neutral-100">{path}</p> : null}
      {children}
    </section>
  );
}

function Sub({ children }: { children: ReactNode }) {
  return <h3 className="text-sm uppercase tracking-wide text-neutral-400">{children}</h3>;
}

const prose = "text-neutral-300";

export default function DevelopersPage() {
  const origin = siteUrl();
  const container = (id: string) => `${origin}/i/${id}`;

  const itemsExample = json(buildItemsListBody([FIRST_ITEM, SECOND_ITEM], 1, 2));
  const itemExample = json(buildItemBody(FIRST_ITEM, container(FIRST_ID)));
  const containerExample = json({
    kind: "preset",
    format: "coilbox",
    payload: {
      title: FIRST_ITEM.title,
      mapName: FIRST_ITEM.map_name,
      gameName: FIRST_ITEM.game_name,
      participants: [],
      startPosType: 2,
      modOptionValues: {},
    },
    container: 1,
    kindVersion: 1,
  });
  const exportExample = json({
    format: "coilbox-hub-export",
    version: 1,
    count: 2,
    items: [
      {
        id: FIRST_ID,
        kind: FIRST_ITEM.kind,
        mode: FIRST_ITEM.mode,
        title: FIRST_ITEM.title,
        description: FIRST_ITEM.description,
        game_name: FIRST_ITEM.game_name,
        game_key: FIRST_ITEM.game_key,
        map_name: FIRST_ITEM.map_name,
        tags: FIRST_ITEM.tags,
        container: JSON.parse(containerExample),
        author_name: FIRST_ITEM.author_name,
        created_at: FIRST_ITEM.created_at,
        updated_at: FIRST_ITEM.created_at,
      },
    ],
  });
  const feedExample = `<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
<id>${origin}/feed.xml?kind=preset</id>
<title type="text">Coilbox Hub: Presets</title>
<subtitle type="text">New presets published on Coilbox Hub.</subtitle>
<updated>2026-10-04T03:16:19.717Z</updated>
<link rel="self" type="application/atom+xml" href="${origin}/feed.xml?kind=preset"/>
<link rel="alternate" type="text/html" href="${origin}/gallery?kind=preset"/>
<author><name>Coilbox Hub</name></author>
<entry>
<id>urn:uuid:${FIRST_ID}</id>
<title type="text">${FIRST_ITEM.title}</title>
<link rel="alternate" type="text/html" href="${origin}/item/${FIRST_ID}"/>
<published>2026-10-04T01:34:37.214Z</published>
<updated>2026-10-04T01:34:37.214Z</updated>
<author><name>${FIRST_ITEM.author_name}</name></author>
<category term="preset" label="Preset"/>
<summary type="text">${FIRST_ITEM.description}</summary>
</entry>
</feed>`;

  return (
    <main
      id="main-content"
      tabIndex={-1}
      className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-10 px-6 py-12"
    >
      <header className="flex flex-col gap-3">
        <h1 className="text-3xl font-semibold tracking-tight">API</h1>
        <p className={prose}>
          Coilbox Hub has a read API. Use it to list the gallery, fetch one item, and read the
          games and map packs the hub holds. Every endpoint below answers a plain{" "}
          <Inline>GET</Inline>, needs no account and no key, and returns JSON unless the heading
          says otherwise.
        </p>
        <p className={prose}>
          The examples use <Inline>{origin}</Inline> as the host. Replace it with the hub you are
          calling.
        </p>
      </header>

      <nav aria-labelledby="endpoints-heading" className="flex flex-col gap-3">
        <h2 id="endpoints-heading" className="text-sm uppercase tracking-wide text-neutral-400">
          Endpoints
        </h2>
        <ul className="flex flex-col gap-2 text-sm">
          {ENDPOINTS.map((endpoint) => (
            <li key={endpoint.id}>
              <a
                href={`#${endpoint.id}`}
                className="text-neutral-300 underline underline-offset-2 transition-colors hover:text-white"
              >
                {endpoint.label}
              </a>{" "}
              <span className="break-all font-mono text-neutral-400">{endpoint.path}</span>
            </li>
          ))}
        </ul>
        <p className="text-sm text-neutral-400">
          Also on this page: <a className="underline underline-offset-2" href="#paging">paging</a>,{" "}
          <a className="underline underline-offset-2" href="#errors">errors</a>,{" "}
          <a className="underline underline-offset-2" href="#caching">caching and limits</a>,{" "}
          <a className="underline underline-offset-2" href="#private">private endpoints</a> and{" "}
          <a className="underline underline-offset-2" href="#terms">terms</a>.
        </p>
      </nav>

      <Section id="items" title="List items" path="GET /api/v1/items">
        <p className={prose}>
          Returns one page of gallery items, newest first, optionally filtered. This is the
          endpoint behind the search and filters on the gallery page.
        </p>
        <Code label="Example request">{`curl "${origin}/api/v1/items?kind=preset&sort=title"`}</Code>
        <Sub>Query parameters</Sub>
        <p className={prose}>
          Every parameter is optional. A parameter the endpoint does not know, or a single value
          parameter sent twice, is a 400 rather than being ignored, so a mistyped filter never
          returns an unfiltered list.
        </p>
        <Fields
          items={ITEMS_PARAMS.map((param) => ({
            name: param.name,
            type: `${param.repeatable ? "string, may repeat" : param.type}. Default: ${param.default}.`,
            text: param.description,
          }))}
        />
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-items</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "page", type: "number", text: "The page this response holds." },
            { name: "page_size", type: "number", text: "How many items fit on a page." },
            { name: "total", type: "number", text: "How many items match the filters, across all pages." },
            { name: "items", type: "array", text: <>The items on this page. Each is described under <a className="underline underline-offset-2" href="#item-fields">item fields</a>.</> },
          ]}
        />
        <Sub>Example response</Sub>
        <Code label="Example response">{itemsExample}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 400, body: '{"error":"Unknown kind: challeng"}', text: "A kind that is not one of the listed kinds." },
            { status: 400, body: '{"error":"Unknown query parameter: foo"}', text: "A parameter name the endpoint does not know." },
            { status: 400, body: '{"error":"Unknown sort: oldest"}', text: "A sort that is not one of the listed values." },
            { status: 400, body: '{"error":"page takes one value, not several. Send it once."}', text: "A single value parameter sent more than once. The first word is the parameter's name." },
            { status: 503, body: '{"error":"The gallery could not be read just now."}', text: "The hub could not read its database. Try again later." },
          ]}
        />
      </Section>

      <Section id="item" title="Get one item" path="GET /api/v1/items/[id]">
        <p className={prose}>
          Returns one item by its id. Take the id from the <Inline>id</Inline> field of a list
          response. The response has the same fields as a list entry, plus{" "}
          <Inline>container_url</Inline>.
        </p>
        <Code label="Example request">{`curl ${origin}/api/v1/items/${FIRST_ID}`}</Code>
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-item</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "item", type: "object", text: "The item." },
          ]}
        />
        <h3 id="item-fields" className="scroll-mt-6 text-sm uppercase tracking-wide text-neutral-400">
          Item fields
        </h3>
        <Fields
          items={[
            { name: "id", type: "string", text: "The item's id, a UUID." },
            { name: "kind", type: "string", text: <>One of {GALLERY_KINDS.join(", ")}.</> },
            { name: "mode", type: "string or null", text: <>Only challenges have one, for example <Inline>warpath</Inline> or <Inline>conquest</Inline>. Null for every other kind.</> },
            { name: "title", type: "string", text: "The item's title." },
            { name: "description", type: "string", text: "The author's description. Can be empty." },
            { name: "game_name", type: "string or null", text: "The game, as a person reads it: the exact pinned build when the item names one, otherwise the shortname." },
            { name: "game_key", type: "string or null", text: "The game's shortname, which is what the game filter matches. Null when the item names its game only by an exact archive name." },
            { name: "map_name", type: "string or null", text: "The map the item uses, if any." },
            { name: "tags", type: "array of strings", text: "The item's tags." },
            { name: "author_name", type: "string", text: "The author's display name." },
            { name: "created_at", type: "string", text: "When the item was published, as a timestamp with a UTC offset." },
            { name: "featured_at", type: "string or null", text: "When a moderator featured the item, or null. Featured items are listed first." },
            { name: "container_url", type: "string", text: <>Only on this endpoint. The address of the item&apos;s container, which is the <a className="underline underline-offset-2" href="#container">next endpoint</a>.</> },
          ]}
        />
        <Sub>Example response</Sub>
        <Code label="Example response">{itemExample}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 404, body: '{"error":"No such item."}', text: "No item has that id, or the item has been withdrawn." },
          ]}
        />
      </Section>

      <Section id="games" title="List games" path="GET /api/v1/games">
        <p className={prose}>
          Returns every game the hub holds, featured games first and then alphabetical. It takes
          no parameters and is not paged.
        </p>
        <Code label="Example request">{`curl ${origin}/api/v1/games`}</Code>
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-games</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "games", type: "array", text: "The games. Each has the fields below." },
            { name: "games[].shortname", type: "string", text: "The game's identifier, used by the game filter of the items endpoint." },
            { name: "games[].title", type: "string", text: "The display name, or the shortname when the game has none." },
            { name: "games[].description", type: "string or null", text: "A short description." },
            { name: "games[].featured", type: "boolean", text: "Whether a moderator has featured the game." },
            { name: "games[].downloads", type: "array", text: <>Where to get the game, best source first, empty when the hub knows none. Each entry has <Inline>kind</Inline> (one of {DOWNLOAD_KINDS.join(", ")}) and <Inline>value</Inline> (a string). A <Inline>github</Inline> entry can have <Inline>asset</Inline>, part of the release archive&apos;s filename. A <Inline>url</Inline> entry can have <Inline>filename</Inline>, the name to save the file as. The hub gives the value as stored and does not resolve it to an address.</> },
            { name: "games[].logo", type: "string or null", text: <>The address of the logo picture. It can be a path starting with <Inline>/assets/</Inline>, which you resolve against the host you called.</> },
            { name: "games[].card", type: "string or null", text: "The address of the card picture, in the same form as the logo." },
            { name: "games[].faction_count", type: "number", text: "How many factions the hub holds for the game." },
            { name: "games[].unit_count", type: "number", text: "How many units the hub holds for the game." },
            { name: "games[].item_count", type: "number", text: "How many gallery items name the game." },
          ]}
        />
        <Sub>Example response</Sub>
        <Code label="Example response">{json(GAMES_EXAMPLE)}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 503, body: '{"error":"The catalog could not be read just now."}', text: "The hub could not read its database. Try again later." },
          ]}
        />
      </Section>

      <Section id="map-packs" title="List map packs" path="GET /api/v1/map-packs">
        <p className={prose}>
          Returns the map packs moderators have put together, featured packs first. It takes no
          parameters and is not paged. A pack with no maps is left out. A pack that is not
          featured is listed with <Inline>featured</Inline> set to false.
        </p>
        <Code label="Example request">{`curl ${origin}/api/v1/map-packs`}</Code>
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-map-packs</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "packs", type: "array", text: "The packs. Each has the fields below." },
            { name: "packs[].id", type: "string", text: "The pack's id." },
            { name: "packs[].title", type: "string", text: "The pack's name." },
            { name: "packs[].blurb", type: "string, optional", text: "A short description. Absent when the pack has none." },
            { name: "packs[].featured", type: "boolean", text: "Whether a moderator has featured the pack." },
            { name: "packs[].maps", type: "array", text: "The maps in the pack, in the order they were added." },
            { name: "packs[].maps[].id", type: "string", text: "The map's name, which is the same as download.springName." },
            { name: "packs[].maps[].title", type: "string", text: "The map's display name, or its name when it has none." },
            { name: "packs[].maps[].download", type: "object", text: <>Always <Inline>{`{ "kind": "map", "springName": "<map name>" }`}</Inline>. The hub holds no archives or download addresses, so this is a name to search for.</> },
            { name: "packs[].maps[].filename", type: "string, optional", text: "The archive's filename, where the hub's catalog holds one." },
          ]}
        />
        <Sub>Example response</Sub>
        <p className="text-sm text-neutral-400">
          This example is constructed from the code, not captured. The hub it was written on had
          no map packs.
        </p>
        <Code label="Example response">{json(MAP_PACKS_EXAMPLE)}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 503, body: '{"error":"The map packs could not be read just now."}', text: "The hub could not read its database. Try again later." },
          ]}
        />
      </Section>

      <Section id="auth" title="Sign in configuration" path="GET /api/v1/auth">
        <p className={prose}>
          Returns what the Coilbox desktop app needs to sign a person in to this hub. A program
          that only reads does not need it. It takes no parameters.
        </p>
        <Code label="Example request">{`curl ${origin}/api/v1/auth`}</Code>
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-auth</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "supabase_url", type: "string", text: "The address of the Supabase project that handles sign in." },
            { name: "publishable_key", type: "string", text: "The key for that project. It is public: the website sends the same key to every browser." },
            { name: "asset_vocabulary", type: "string", text: "A digest of the picture encoding rules the app must follow when it uploads." },
            { name: "map_catalog", type: "string", text: "A digest of the map fact catalog the app must describe maps by." },
          ]}
        />
        <Sub>Example response</Sub>
        <p className="text-sm text-neutral-400">
          The address and the key are placeholders. The two digests are the real values.
        </p>
        <Code label="Example response">{json(AUTH_EXAMPLE)}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 503, body: '{"error":"This deployment has not configured Supabase sign-in."}', text: "The hub is missing its sign in settings." },
          ]}
        />
      </Section>

      <Section id="container" title="Get an item's container" path="GET /i/[id]">
        <p className={prose}>
          Returns the item itself, in the format Coilbox imports. The address does not change
          for the life of the item, which is why Import links use it. The shape of the body
          depends on the item&apos;s kind and is the format the Coilbox app reads.
        </p>
        <Code label="Example request">{`curl ${container(FIRST_ID)}`}</Code>
        <Sub>Example response</Sub>
        <Code label="Example response">{containerExample}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 404, body: '{"error":"No such item."}', text: "No item has that id, or the item has been withdrawn." },
          ]}
        />
      </Section>

      <Section id="export" title="Export the gallery" path="GET /export">
        <p className={prose}>
          Returns every public item, with its container, as one JSON file. Use it to back up or
          rehost the gallery. It takes no parameters and is not paged. Withdrawn items are not in
          it.
        </p>
        <Code label="Example request">{`curl -o coilbox-hub.json ${origin}/export`}</Code>
        <Sub>Response</Sub>
        <Fields
          items={[
            { name: "format", type: "string", text: <>Always <Inline>coilbox-hub-export</Inline>.</> },
            { name: "version", type: "number", text: <>Currently <Inline>1</Inline>.</> },
            { name: "count", type: "number", text: "How many items the file holds." },
            { name: "items", type: "array", text: <>The items, newest first. Each has the item fields above except <Inline>featured_at</Inline>, and in place of <Inline>container_url</Inline> it has <Inline>container</Inline>, the body of the container endpoint, and <Inline>updated_at</Inline>, a timestamp of the last edit.</> },
          ]}
        />
        <Sub>Example response</Sub>
        <p className="text-sm text-neutral-400">
          Trimmed. The real response lists every item, and here the second one is removed while{" "}
          <Inline>count</Inline> still reads 2.
        </p>
        <Code label="Example response">{exportExample}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 503, body: '{"error":"<reason>"}', text: "The hub could not read its database. The reason is the database's own message." },
          ]}
        />
      </Section>

      <Section id="feed" title="Atom feed" path="GET /feed.xml">
        <p className={prose}>
          Returns the {FEED_ENTRIES} newest items as an Atom feed, for a feed reader or a chat
          channel. The response is XML, not JSON. Each entry links to the item&apos;s page on the hub.
        </p>
        <Code label="Example request">{`curl "${origin}/feed.xml?kind=preset"`}</Code>
        <Sub>Query parameters</Sub>
        <Fields
          items={[
            {
              name: "kind",
              type: "string. Default: none, so every kind.",
              text: <>One of {GALLERY_KINDS.join(", ")}. Leave it out or empty for all kinds. Unlike the items endpoint, the feed takes one kind, and ignores other parameters.</>,
            },
          ]}
        />
        <Sub>Example response</Sub>
        <p className="text-sm text-neutral-400">Trimmed to one entry. A feed holds up to {FEED_ENTRIES}.</p>
        <Code label="Example response">{feedExample}</Code>
        <Sub>Errors</Sub>
        <Errors
          rows={[
            { status: 400, body: `Unknown kind. Use one of: ${GALLERY_KINDS.join(", ")}.`, text: "A kind that is not one of the listed kinds. The body is plain text, not JSON." },
          ]}
        />
      </Section>

      <Section id="paging" title="Paging">
        <p className={prose}>
          Only the items endpoint is paged. Every page holds up to {PAGE_SIZE} items. The page
          size is fixed and there is no parameter to change it.
        </p>
        <p className={prose}>
          Ask for a page with <Inline>page</Inline>, counting from 1. The response tells you
          where you are: <Inline>page</Inline>, <Inline>page_size</Inline> and{" "}
          <Inline>total</Inline>, the number of items that match across all pages. There is more
          to read when <Inline>page * page_size</Inline> is less than <Inline>total</Inline>. The
          last page is <Inline>ceil(total / page_size)</Inline>.
        </p>
        <p className={prose}>
          A page past the end is not an error. It returns status 200 with an empty{" "}
          <Inline>items</Inline> array and the same <Inline>total</Inline>.
        </p>
        <p className={prose}>
          Worked example with made up numbers. A request without a <Inline>page</Inline> returns{" "}
          <Inline>{`"page": 1, "page_size": ${PAGE_SIZE}, "total": 50`}</Inline>. Since 1 * {PAGE_SIZE} is
          less than 50, there is more. Pages 2 and 3 follow. Page 2 holds {PAGE_SIZE} items. Page 3
          holds the last 2, and 3 * {PAGE_SIZE} is not less than 50, so you stop.
        </p>
        <Code label="Example requests">{`curl "${origin}/api/v1/items?page=2"
curl "${origin}/api/v1/items?page=3"`}</Code>
        <p className={prose}>
          Items are ordered live, so a page is a window on a list that changes. An item published
          between two of your requests moves the items below it down, and you can see one twice.
        </p>
      </Section>

      <Section id="errors" title="Errors">
        <p className={prose}>
          The JSON endpoints report an error as a status code and a body of the form{" "}
          <Inline>{`{ "error": "<message>" }`}</Inline>. Read the status code first. The message is for
          people and its wording can change. Each endpoint above lists the errors it can return.
        </p>
        <p className={prose}>
          The feed is the exception: its error is plain text. A 503 from any <Inline>/api</Inline>{" "}
          path with a message about Supabase settings means the hub itself is misconfigured.
        </p>
      </Section>

      <Section id="caching" title="Caching and limits">
        <p className={prose}>
          A successful response carries a <Inline>Cache-Control</Inline> header, and an error
          response does not. The lifetimes are:
        </p>
        <ul className="flex flex-col gap-1 text-sm text-neutral-300">
          <li><Inline>/api/v1/items</Inline>, <Inline>/api/v1/items/[id]</Inline>, <Inline>/api/v1/games</Inline>, <Inline>/api/v1/map-packs</Inline> and <Inline>/i/[id]</Inline>: 60 seconds.</li>
          <li><Inline>/export</Inline>: 300 seconds.</li>
          <li><Inline>/api/v1/auth</Inline>: 86400 seconds, one day.</li>
          <li><Inline>/feed.xml</Inline>: no <Inline>Cache-Control</Inline> header. The hub builds each feed at most once an hour, and again as soon as someone publishes, edits or withdraws an item.</li>
        </ul>
        <p className={prose}>
          There is no rate limit on these endpoints in the hub&apos;s code, and no published limit.
          Please cache what you fetch and do not request an endpoint more often than its cache
          lifetime above.
        </p>
        <p className={prose}>
          The <Inline>/api/v1</Inline> endpoints, <Inline>/i/[id]</Inline> and{" "}
          <Inline>/export</Inline> send <Inline>Access-Control-Allow-Origin: *</Inline>, so a
          browser page on any origin can call them. The feed does not send CORS headers.
        </p>
      </Section>

      <Section id="private" title="Private endpoints">
        <p className={prose}>
          The hub has other endpoints that the Coilbox desktop app uses to publish items and to
          send pictures, map facts and game facts. They are private to the app. They are not
          documented here and are not supported for other clients.
        </p>
      </Section>

      <Section id="terms" title="Terms">
        <p className={prose}>
          The hub states no licence for the items and maps it lists. Each item&apos;s page shows
          its author.
        </p>
      </Section>
    </main>
  );
}
