import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { skirmish } from "@/components/art/drawings";
import { MapFigure } from "@/components/MapFigure";
import { MapMirrors } from "@/components/MapMirrors";
import { MapPreview } from "@/components/MapPreview";
import { MapPlayedOn } from "@/components/MapPlayedOn";
import { requestOrigin } from "@/lib/gallery/origin";
import { mapSizeLabel, mapTitle, playerCountLabel, windLabel } from "@/lib/maps/labels";
import { loadMapPage, type MapPage } from "@/lib/maps/page";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * Everything the catalog holds about one map (#190).
 *
 * Addressed by slug rather than by id, which is the one place the map catalog
 * parts company with `/item/[id]`. An item is something one person published and
 * links to once. A map page is what somebody pastes into lobby chat, and
 * `/map/comet-catcher-remake-1-8` survives that better than a uuid does.
 *
 * The slug is read, never worked out. `lib/maps/slug.ts` derives one when a map
 * is first submitted and `public.map.slug` stores it, because the numeric suffix
 * a collision takes depends on what else was in the table at the time. A page
 * that recomputed one could hand a map another map's URL.
 *
 * A map the licence gate refuses is not found, exactly as if the hub had never
 * heard of it, which is the answer `/api/v1/maps/lookup` gives for the same map.
 * `lib/maps/page.ts` holds that decision and the reading behind it.
 */

/** Once per request. `generateMetadata` and the page both ask, and the
 *  `map_facts` read is a POST, which the router does not deduplicate the way
 *  it does the GET selects, so without `cache` the heaviest read on the page
 *  ran twice. */
const load = cache(async (slug: string): Promise<MapPage | null> => {
  // The secret key for the facts and the gate they come through, and the
  // visitor's own client for everything else. `lib/maps/page.ts` sets out why a
  // public page reads anything as `service_role` at all.
  return loadMapPage(await createClient(), createAdminClient(), slug);
});

/** Fainter than an item page's, which sits at 0.16 for this drawing. The
 *  minimap is the page, and a backdrop competing with a picture of terrain
 *  makes the terrain harder to read. */
const BACKDROP_STRENGTH = 0.05;

/** The column the page's words are set in. It is applied to each block rather
 *  than to one wrapper because the terrain is not in it: a scene runs to both
 *  edges of the window, and it can only do that if nothing above it is holding
 *  the whole page to 48 rem. */
const COLUMN = "mx-auto w-full max-w-3xl px-6";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const page = await load((await params).slug);
  if (!page) return { title: "Not found - Coilbox Hub" };

  const { mapName, facts } = page;
  const title = mapTitle(facts.display_name, mapName);
  // What somebody deciding whether to open the link wants: how big it is and
  // how many it takes. The archive's own words come after, since plenty of
  // archives have none.
  const measures = [
    mapSizeLabel(facts.width_elmos, facts.height_elmos),
    playerCountLabel(facts.points.start.length),
  ].filter(Boolean);
  const description = [measures.join(", "), facts.description].filter(Boolean).join(" - ");

  return {
    title: `${title} - Coilbox Hub`,
    description,
    openGraph: { title, description, type: "article" },
  };
}

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-neutral-400">{term}</dt>
      <dd className="text-sm text-neutral-100">{children}</dd>
    </div>
  );
}

export default async function Map({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const page = await load(slug);
  if (!page) notFound();

  const { mapName, facts, picture, preview, played, mirrors } = page;
  const origin = await requestOrigin();
  const title = mapTitle(facts.display_name, mapName);
  const players = playerCountLabel(facts.points.start.length);
  const wind = windLabel(facts.min_wind, facts.max_wind);
  // One element, rendered in one of two places. It is server markup either way,
  // and passing it as a child of a client component keeps it that way.
  const figure = (
    <MapFigure name={mapName} map={facts} points={facts.points} picture={picture} />
  );

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={skirmish} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 flex w-full flex-col gap-8 py-12">
        <div className={`${COLUMN} flex flex-col gap-3`}>
          <h1 className="break-words text-3xl font-semibold tracking-tight">{title}</h1>
          {title === mapName ? null : (
            // The canonical name, which is what a lobby shows and what somebody
            // searching for the archive has to type. Shown whenever the archive
            // gave a friendlier name, since that name is not what identifies the
            // map anywhere else.
            <p className="break-words text-sm text-neutral-500">{mapName}</p>
          )}
          {facts.description ? (
            <p className="whitespace-pre-wrap text-neutral-400">{facts.description}</p>
          ) : null}
        </div>

        {/* The figure is the page, so it gets the whole width of the window
            rather than the width of the column the words are set in. Where the
            hub holds a height overlay, `MapPreview` draws the same map as
            terrain and takes the figure's place once it has, which is why the
            figure is its child rather than its sibling. Most maps have no
            overlay and stay flat, and nothing on the page says a view was
            withheld.

            Only the terrain spans the page. The flat figure is a picture at the
            map's own proportions and blowing it up to 1,600 pixels would be
            showing somebody a 512 pixel minimap enlarged, so it keeps the
            column. */}
        {preview ? (
          <MapPreview name={mapName} preview={preview} column={COLUMN}>
            {figure}
          </MapPreview>
        ) : (
          <div className={COLUMN}>{figure}</div>
        )}

        <div className={`${COLUMN} flex flex-col gap-8`}>
          <dl className="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
            <Fact term="Size">{mapSizeLabel(facts.width_elmos, facts.height_elmos)}</Fact>
            {players ? <Fact term="Players">{players}</Fact> : null}
            {wind ? <Fact term="Wind">{wind}</Fact> : null}
            {facts.tidal_strength === null ? null : (
              <Fact term="Tidal">{facts.tidal_strength}</Fact>
            )}
            {facts.void_water ? (
              // Only when the archive says so outright. A map that declares
              // nothing about water is not a map with water, and saying "some"
              // would be the hub making the measurement up.
              <Fact term="Water">None</Fact>
            ) : null}
            {facts.authors.length > 0 ? (
              <Fact term="Made by">
                <ul className="flex flex-col gap-0.5">
                  {facts.authors.map((author) => (
                    <li key={author.key}>
                      {/* The listing in #189, which is where a key becomes
                          everything that person made. The link is written now
                          because the key is what it will be. */}
                      <Link
                        href={`/maps?author=${encodeURIComponent(author.key)}`}
                        className="hover:text-white active:text-white"
                      >
                        {author.name}
                      </Link>
                    </li>
                  ))}
                </ul>
              </Fact>
            ) : null}
          </dl>

          {facts.tags.length > 0 ? (
            <ul className="flex flex-wrap gap-1.5">
              {facts.tags.map((tag) => (
                <li key={tag}>
                  <Link
                    href={`/maps?tag=${encodeURIComponent(tag)}`}
                    className="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200 active:text-neutral-200"
                  >
                    {tag}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}

          <MapMirrors links={mirrors} />

          <MapPlayedOn mapName={mapName} items={played} origin={origin} />
        </div>
      </div>
    </main>
  );
}
