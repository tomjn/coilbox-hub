@extends('layouts.app')

{{-- app/developers/page.tsx. The page documents the desktop API, so the example bodies come from the controller. --}}
@section('content')
<main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-10 px-6 py-12">
    <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-semibold tracking-tight">API</h1>
        <p class="text-neutral-300">
            Coilbox Hub has a read API. Use it to list the gallery, fetch one item, and read the
            games and map packs the hub holds. Every endpoint below answers a plain
            <x-developers.inline>GET</x-developers.inline>, needs no account and no key, and returns JSON unless the heading
            says otherwise.
        </p>
        <p class="text-neutral-300">
            The examples use <x-developers.inline>{{ $origin }}</x-developers.inline> as the host. Replace it with the hub you are
            calling.
        </p>
    </header>

    <nav aria-labelledby="endpoints-heading" class="flex flex-col gap-3">
        <h2 id="endpoints-heading" class="text-sm uppercase tracking-wide text-neutral-400">
            Endpoints
        </h2>
        <ul class="flex flex-col gap-2 text-sm">
            @foreach ($endpoints as $endpoint)
            <li>
                <a href="#{{ $endpoint['id'] }}" class="text-neutral-300 underline underline-offset-2 transition-colors hover:text-white">{{ $endpoint['label'] }}</a>
                <span class="break-all font-mono text-neutral-400">{{ $endpoint['path'] }}</span>
            </li>
            @endforeach
        </ul>
        <p class="text-sm text-neutral-400">
            Also on this page: <a class="underline underline-offset-2" href="#paging">paging</a>,
            <a class="underline underline-offset-2" href="#errors">errors</a>,
            <a class="underline underline-offset-2" href="#caching">caching and limits</a>,
            and <a class="underline underline-offset-2" href="#private">private endpoints</a>.
        </p>
    </nav>

    <x-developers.section id="items" title="List items" path="GET /api/v1/items">
        <p class="text-neutral-300">
            Returns one page of gallery items, newest first, optionally filtered. This is the
            endpoint behind the search and filters on the gallery page.
        </p>
        <x-developers.code label="Example request">curl "{{ $origin }}/api/v1/items?kind=preset&amp;sort=title"</x-developers.code>
        <x-developers.sub>Query parameters</x-developers.sub>
        <p class="text-neutral-300">
            Every parameter is optional. A parameter the endpoint does not know, or a single value
            parameter sent twice, is a 400 rather than being ignored, so a mistyped filter never
            returns an unfiltered list.
        </p>
        <x-developers.fields>
            @foreach ($itemsParams as $param)
            <x-developers.field :name="$param['name']" :type="$param['type']">{{ $param['text'] }}</x-developers.field>
            @endforeach
        </x-developers.fields>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-items</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="page" type="number">The page this response holds.</x-developers.field>
            <x-developers.field name="page_size" type="number">How many items fit on a page.</x-developers.field>
            <x-developers.field name="total" type="number">How many items match the filters, across all pages.</x-developers.field>
            <x-developers.field name="items" type="array">The items on this page. Each is described under <a class="underline underline-offset-2" href="#item-fields">item fields</a>.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <x-developers.code label="Example response">{{ $itemsExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['items']" />
    </x-developers.section>

    <x-developers.section id="item" title="Get one item" path="GET /api/v1/items/[id]">
        <p class="text-neutral-300">
            Returns one item by its id. Take the id from the <x-developers.inline>id</x-developers.inline> field of a list
            response. The response has the same fields as a list entry, plus
            <x-developers.inline>container_url</x-developers.inline>.
        </p>
        <x-developers.code label="Example request">curl {{ $origin }}/api/v1/items/{{ $firstId }}</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-item</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="item" type="object">The item.</x-developers.field>
        </x-developers.fields>
        <h3 id="item-fields" class="scroll-mt-6 text-sm uppercase tracking-wide text-neutral-400">
            Item fields
        </h3>
        <x-developers.fields>
            <x-developers.field name="id" type="string">The item's id, a UUID.</x-developers.field>
            <x-developers.field name="kind" type="string">One of {{ $kinds }}.</x-developers.field>
            <x-developers.field name="mode" type="string or null">Only challenges have one, for example <x-developers.inline>warpath</x-developers.inline> or <x-developers.inline>conquest</x-developers.inline>. Null for every other kind.</x-developers.field>
            <x-developers.field name="title" type="string">The item's title.</x-developers.field>
            <x-developers.field name="description" type="string">The author's description. Can be empty.</x-developers.field>
            <x-developers.field name="game_name" type="string or null">The game, as a person reads it: the exact pinned build when the item names one, otherwise the shortname.</x-developers.field>
            <x-developers.field name="game_key" type="string or null">The game's shortname, which is what the game filter matches. Null when the item names its game only by an exact archive name.</x-developers.field>
            <x-developers.field name="map_name" type="string or null">The map the item uses, if any.</x-developers.field>
            <x-developers.field name="tags" type="array of strings">The item's tags.</x-developers.field>
            <x-developers.field name="author_name" type="string">The author's display name.</x-developers.field>
            <x-developers.field name="author_id" type="string or null">The author's account id, a UUID. Pass it as <x-developers.inline>account</x-developers.inline> to list that account's items. Unlike <x-developers.inline>author_name</x-developers.inline>, it does not change when the account is renamed.</x-developers.field>
            <x-developers.field name="created_at" type="string">When the item was published, as a timestamp with a UTC offset.</x-developers.field>
            <x-developers.field name="featured_at" type="string or null">When a moderator featured the item, or null. Featured items are listed first.</x-developers.field>
            <x-developers.field name="container_url" type="string">Only on this endpoint. The address of the item's container, which is the <a class="underline underline-offset-2" href="#container">next endpoint</a>.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <x-developers.code label="Example response">{{ $itemExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['item']" />
    </x-developers.section>

    <x-developers.section id="games" title="List games" path="GET /api/v1/games">
        <p class="text-neutral-300">
            Returns every game the hub holds, featured games first and then alphabetical. It takes
            no parameters and is not paged.
        </p>
        <x-developers.code label="Example request">curl {{ $origin }}/api/v1/games</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-games</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="games" type="array">The games. Each has the fields below.</x-developers.field>
            <x-developers.field name="games[].shortname" type="string">The game's identifier, used by the game filter of the items endpoint.</x-developers.field>
            <x-developers.field name="games[].title" type="string">The display name, or the shortname when the game has none.</x-developers.field>
            <x-developers.field name="games[].description" type="string or null">A short description.</x-developers.field>
            <x-developers.field name="games[].featured" type="boolean">Whether a moderator has featured the game.</x-developers.field>
            <x-developers.field name="games[].downloads" type="array">Where to get the game, best source first, empty when the hub knows none. Each entry has <x-developers.inline>kind</x-developers.inline> (one of {{ $downloadKinds }}) and <x-developers.inline>value</x-developers.inline> (a string). A <x-developers.inline>github</x-developers.inline> entry can have <x-developers.inline>asset</x-developers.inline>, part of the release archive's filename. A <x-developers.inline>url</x-developers.inline> entry can have <x-developers.inline>filename</x-developers.inline>, the name to save the file as. The hub gives the value as stored and does not resolve it to an address.</x-developers.field>
            <x-developers.field name="games[].logo" type="string or null">The address of the logo picture. It can be a path starting with <x-developers.inline>/assets/</x-developers.inline>, which you resolve against the host you called.</x-developers.field>
            <x-developers.field name="games[].card" type="string or null">The address of the card picture, in the same form as the logo.</x-developers.field>
            <x-developers.field name="games[].banner" type="string or null">The address of the banner the game's page draws as its header, in the same form as the logo.</x-developers.field>
            <x-developers.field name="games[].faction_count" type="number">How many factions the hub holds for the game.</x-developers.field>
            <x-developers.field name="games[].unit_count" type="number">How many units the hub holds for the game.</x-developers.field>
            <x-developers.field name="games[].item_count" type="number">How many gallery items name the game.</x-developers.field>
            <x-developers.field name="games[].base_game" type="string or null">The shortname of the game this one is built on, when it is a mutator. Null for any other game. The hub may not hold a game with that shortname yet.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <x-developers.code label="Example response">{{ $gamesExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['games']" />
    </x-developers.section>

    <x-developers.section id="map-packs" title="List map packs" path="GET /api/v1/map-packs">
        <p class="text-neutral-300">
            Returns the map packs moderators have put together, featured packs first. It takes no
            parameters and is not paged. A pack with no maps is left out. A pack that is not
            featured is listed with <x-developers.inline>featured</x-developers.inline> set to false.
        </p>
        <x-developers.code label="Example request">curl {{ $origin }}/api/v1/map-packs</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-map-packs</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="packs" type="array">The packs. Each has the fields below.</x-developers.field>
            <x-developers.field name="packs[].id" type="string">The pack's id.</x-developers.field>
            <x-developers.field name="packs[].title" type="string">The pack's name.</x-developers.field>
            <x-developers.field name="packs[].blurb" type="string, optional">A short description. Absent when the pack has none.</x-developers.field>
            <x-developers.field name="packs[].featured" type="boolean">Whether a moderator has featured the pack.</x-developers.field>
            <x-developers.field name="packs[].maps" type="array">The maps in the pack, in the order they were added.</x-developers.field>
            <x-developers.field name="packs[].maps[].id" type="string">The map's name, which is the same as download.springName.</x-developers.field>
            <x-developers.field name="packs[].maps[].title" type="string">The map's display name, or its name when it has none.</x-developers.field>
            <x-developers.field name="packs[].maps[].download" type="object">Always <x-developers.inline>{{ '{ "kind": "map", "springName": "<map name>" }' }}</x-developers.inline>. The hub holds no archives or download addresses, so this is a name to search for.</x-developers.field>
            <x-developers.field name="packs[].maps[].filename" type="string, optional">The archive's filename, where the hub's catalog holds one.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <p class="text-sm text-neutral-400">
            This example is constructed from the code, not captured. The hub it was written on had
            no map packs.
        </p>
        <x-developers.code label="Example response">{{ $mapPacksExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['mapPacks']" />
    </x-developers.section>

    <x-developers.section id="auth" title="Sign in configuration" path="GET /api/v1/auth">
        <p class="text-neutral-300">
            Returns what the Coilbox desktop app needs to sign a person in to this hub. A program
            that only reads does not need it. It takes no parameters.
        </p>
        <x-developers.code label="Example request">curl {{ $origin }}/api/v1/auth</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-auth</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="supabase_url" type="string">The address of the Supabase project that handles sign in.</x-developers.field>
            <x-developers.field name="publishable_key" type="string">The key for that project. It is public: the website sends the same key to every browser.</x-developers.field>
            <x-developers.field name="asset_vocabulary" type="string">A digest of the picture encoding rules the app must follow when it uploads.</x-developers.field>
            <x-developers.field name="map_catalog" type="string">A digest of the map fact catalog the app must describe maps by.</x-developers.field>
            <x-developers.field name="providers" type="string[], optional">The sign in providers this hub offers, in the order to show them. Each is a Supabase provider id.</x-developers.field>
            <x-developers.field name="email_password" type="boolean, optional">Whether email and password sign in is available.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <p class="text-sm text-neutral-400">
            The address and the key are placeholders. The two digests are the real values.
        </p>
        <x-developers.code label="Example response">{{ $authExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['auth']" />
    </x-developers.section>

    <x-developers.section id="profile" title="Get the signed in account's name" path="GET /api/v1/profile">
        <p class="text-neutral-300">
            Returns the display name of the account that owns an access token. It takes no
            parameters. Send the token as <x-developers.inline>Authorization: Bearer &lt;token&gt;</x-developers.inline>. The
            answer is never cached.
        </p>
        <x-developers.code label="Example request">curl -H "Authorization: Bearer $TOKEN" {{ $origin }}/api/v1/profile</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="display_name" type="string or null">The account's display name, or null if it has none.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['profile']" />
    </x-developers.section>

    <x-developers.section id="container" title="Get an item's container" path="GET /i/[id]">
        <p class="text-neutral-300">
            Returns the item itself, in the format Coilbox imports. The address does not change
            for the life of the item, which is why Import links use it. The shape of the body
            depends on the item's kind and is the format the Coilbox app reads.
        </p>
        <x-developers.code label="Example request">curl {{ $origin }}/i/{{ $firstId }}</x-developers.code>
        <x-developers.sub>Example response</x-developers.sub>
        <x-developers.code label="Example response">{{ $containerExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['container']" />
    </x-developers.section>

    <x-developers.section id="export" title="Export the gallery" path="GET /export">
        <p class="text-neutral-300">
            Returns every public item, with its container, as one JSON file. It takes no parameters
            and is not paged. Withdrawn items are not in it.
        </p>
        <x-developers.code label="Example request">curl -o coilbox-hub.json {{ $origin }}/export</x-developers.code>
        <x-developers.sub>Response</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="format" type="string">Always <x-developers.inline>coilbox-hub-export</x-developers.inline>.</x-developers.field>
            <x-developers.field name="version" type="number">Currently <x-developers.inline>1</x-developers.inline>.</x-developers.field>
            <x-developers.field name="count" type="number">How many items the file holds.</x-developers.field>
            <x-developers.field name="items" type="array">The items, newest first. Each has the item fields above except <x-developers.inline>featured_at</x-developers.inline>, and in place of <x-developers.inline>container_url</x-developers.inline> it has <x-developers.inline>container</x-developers.inline>, the body of the container endpoint, and <x-developers.inline>updated_at</x-developers.inline>, a timestamp of the last edit.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <p class="text-sm text-neutral-400">
            Trimmed. The real response lists every item, and here the second one is removed while
            <x-developers.inline>count</x-developers.inline> still reads 2.
        </p>
        <x-developers.code label="Example response">{{ $exportExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['export']" />
    </x-developers.section>

    <x-developers.section id="feed" title="Atom feed" path="GET /feed.xml">
        <p class="text-neutral-300">
            Returns the {{ $feedEntries }} newest items as an Atom feed, for a feed reader or a chat
            channel. The response is XML, not JSON. Each entry links to the item's page on the hub.
        </p>
        <x-developers.code label="Example request">curl "{{ $origin }}/feed.xml?kind=preset"</x-developers.code>
        <x-developers.sub>Query parameters</x-developers.sub>
        <x-developers.fields>
            <x-developers.field name="kind" type="string. Default: none, so every kind.">One of {{ $kinds }}. Leave it out or empty for all kinds. Unlike the items endpoint, the feed takes one kind, and ignores other parameters.</x-developers.field>
        </x-developers.fields>
        <x-developers.sub>Example response</x-developers.sub>
        <p class="text-sm text-neutral-400">Trimmed to one entry. A feed holds up to {{ $feedEntries }}.</p>
        <x-developers.code label="Example response">{{ $feedExample }}</x-developers.code>
        <x-developers.sub>Errors</x-developers.sub>
        <x-developers.errors :rows="$apiErrors['feed']" />
    </x-developers.section>

    <x-developers.section id="paging" title="Paging">
        <p class="text-neutral-300">
            Only the items endpoint is paged. Every page holds up to {{ $pageSize }} items. The page
            size is fixed and there is no parameter to change it.
        </p>
        <p class="text-neutral-300">
            Ask for a page with <x-developers.inline>page</x-developers.inline>, counting from 1. The response tells you
            where you are: <x-developers.inline>page</x-developers.inline>, <x-developers.inline>page_size</x-developers.inline> and
            <x-developers.inline>total</x-developers.inline>, the number of items that match across all pages. There is more
            to read when <x-developers.inline>page * page_size</x-developers.inline> is less than <x-developers.inline>total</x-developers.inline>. The
            last page is <x-developers.inline>ceil(total / page_size)</x-developers.inline>.
        </p>
        <p class="text-neutral-300">
            A page past the end is not an error. It returns status 200 with an empty
            <x-developers.inline>items</x-developers.inline> array and the same <x-developers.inline>total</x-developers.inline>. The exception is a
            page above {{ $maxPage }}, which is a 400 because no page that high can hold an
            item.
        </p>
        <p class="text-neutral-300">
            Worked example with made up numbers. A request without a <x-developers.inline>page</x-developers.inline> returns
            <x-developers.inline>{{ '"page": 1, "page_size": '.$pageSize.', "total": 50' }}</x-developers.inline>. Since 1 * {{ $pageSize }} is
            less than 50, there is more. Pages 2 and 3 follow. Page 2 holds {{ $pageSize }} items. Page 3
            holds the last 2, and 3 * {{ $pageSize }} is not less than 50, so you stop.
        </p>
        <x-developers.code label="Example requests">curl "{{ $origin }}/api/v1/items?page=2"
curl "{{ $origin }}/api/v1/items?page=3"</x-developers.code>
        <p class="text-neutral-300">
            Items are ordered live, so a page is a window on a list that changes. An item published
            between two of your requests moves the items below it down, and you can see one twice.
        </p>
    </x-developers.section>

    <x-developers.section id="errors" title="Errors">
        <p class="text-neutral-300">
            The JSON endpoints report an error as a status code and a body of the form
            <x-developers.inline>{{ '{ "error": "<message>" }' }}</x-developers.inline>. Read the status code first. The message is for
            people and its wording can change. Each endpoint above lists the errors it can return.
        </p>
        <p class="text-neutral-300">
            The feed is the exception: its error is plain text. A 503 from any <x-developers.inline>/api</x-developers.inline>
            path with a message about Supabase settings means the hub itself is misconfigured.
        </p>
    </x-developers.section>

    <x-developers.section id="caching" title="Caching and limits">
        <p class="text-neutral-300">
            A successful response carries a <x-developers.inline>Cache-Control</x-developers.inline> header, and an error
            response does not. The lifetimes are:
        </p>
        <ul class="flex flex-col gap-1 text-sm text-neutral-300">
            <li><x-developers.inline>/api/v1/items</x-developers.inline>, <x-developers.inline>/api/v1/items/[id]</x-developers.inline>, <x-developers.inline>/api/v1/games</x-developers.inline>, <x-developers.inline>/api/v1/map-packs</x-developers.inline> and <x-developers.inline>/i/[id]</x-developers.inline>: 60 seconds.</li>
            <li><x-developers.inline>/export</x-developers.inline>: 300 seconds.</li>
            <li><x-developers.inline>/api/v1/auth</x-developers.inline>: 86400 seconds, one day.</li>
            <li><x-developers.inline>/feed.xml</x-developers.inline>: no <x-developers.inline>Cache-Control</x-developers.inline> header. The hub builds each feed at most once an hour, and again as soon as someone publishes, edits or withdraws an item.</li>
        </ul>
        <p class="text-neutral-300">
            There is no rate limit on these endpoints in the hub's code, and no published limit.
            Please cache what you fetch and do not request an endpoint more often than its cache
            lifetime above.
        </p>
        <p class="text-neutral-300">
            The <x-developers.inline>/api/v1</x-developers.inline> endpoints, <x-developers.inline>/i/[id]</x-developers.inline> and
            <x-developers.inline>/export</x-developers.inline> send <x-developers.inline>Access-Control-Allow-Origin: *</x-developers.inline>, so a
            browser page on any origin can call them. The feed does not send CORS headers.
        </p>
    </x-developers.section>

    <x-developers.section id="private" title="Private endpoints">
        <p class="text-neutral-300">
            The hub has other endpoints that the Coilbox desktop app uses to publish items and to
            send pictures, map facts and game facts. They are private to the app. They are not
            documented here and are not supported for other clients.
        </p>
    </x-developers.section>
</main>
@endsection
