import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { skirmish } from "@/components/art/drawings";
import { MapFigure } from "@/components/MapFigure";
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

async function load(slug: string): Promise<MapPage | null> {
  // The admin client for the licence gate and nothing else. Everything else
  // here is public, so it is read with the visitor's own client and stays
  // underneath row level security.
  return loadMapPage(await createClient(), createAdminClient(), slug);
}

/** Fainter than an item page's, which sits at 0.16 for this drawing. The
 *  minimap is the page, and a backdrop competing with a picture of terrain
 *  makes the terrain harder to read. */
const BACKDROP_STRENGTH = 0.05;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const page = await load((await params).slug);
  if (!page) return { title: "Not found - Coilbox Hub" };

  const title = mapTitle(page.map);
  // What somebody deciding whether to open the link wants: how big it is and
  // how many it takes. The archive's own words come after, since plenty of
  // archives have none.
  const facts = [
    mapSizeLabel(page.map.width_elmos, page.map.height_elmos),
    playerCountLabel(page.spots.start.length),
  ].filter(Boolean);
  const description = [facts.join(", "), page.map.description].filter(Boolean).join(" - ");

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

  const { map, spots, tags, authors, picture, played } = page;
  const origin = await requestOrigin();
  const title = mapTitle(map);
  const players = playerCountLabel(spots.start.length);
  const wind = windLabel(map.min_wind, map.max_wind);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={skirmish} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-3">
          <h1 className="break-words text-3xl font-semibold tracking-tight">{title}</h1>
          {title === map.map_name ? null : (
            // The canonical name, which is what a lobby shows and what somebody
            // searching for the archive has to type. Shown whenever the archive
            // gave a friendlier name, since that name is not what identifies the
            // map anywhere else.
            <p className="break-words text-sm text-neutral-500">{map.map_name}</p>
          )}
          {map.description ? (
            <p className="whitespace-pre-wrap text-neutral-400">{map.description}</p>
          ) : null}
        </div>

        <MapFigure
          map={map}
          spots={spots}
          picture={picture}
          className="mx-auto w-full max-w-sm"
        />

        <dl className="grid grid-cols-2 gap-6 border-t border-neutral-900 pt-6 sm:grid-cols-4">
          <Fact term="Size">{mapSizeLabel(map.width_elmos, map.height_elmos)}</Fact>
          {players ? <Fact term="Players">{players}</Fact> : null}
          {wind ? <Fact term="Wind">{wind}</Fact> : null}
          {map.tidal_strength === null ? null : (
            <Fact term="Tidal">{map.tidal_strength}</Fact>
          )}
          {map.void_water ? (
            // Only when the archive says so outright. A map that declares
            // nothing about water is not a map with water, and saying "some"
            // would be the hub making the measurement up.
            <Fact term="Water">None</Fact>
          ) : null}
          {authors.length > 0 ? (
            <Fact term="Made by">
              <ul className="flex flex-col gap-0.5">
                {authors.map((author) => (
                  <li key={author.key}>
                    {/* The listing in #189, which is where a key becomes
                        everything that person made. The link is written now
                        because the key is what it will be. */}
                    <Link
                      href={`/maps?author=${encodeURIComponent(author.key)}`}
                      className="hover:text-white"
                    >
                      {author.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </Fact>
          ) : null}
        </dl>

        {tags.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5">
            {tags.map((tag) => (
              <li key={tag}>
                <Link
                  href={`/maps?tag=${encodeURIComponent(tag)}`}
                  className="inline-block rounded bg-neutral-900 px-2 py-1 text-xs text-neutral-400 transition-colors hover:text-neutral-200"
                >
                  {tag}
                </Link>
              </li>
            ))}
          </ul>
        ) : null}

        <MapPlayedOn mapName={map.map_name} items={played} origin={origin} />
      </div>
    </main>
  );
}
