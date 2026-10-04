import type { Metadata } from "next";
import Link from "next/link";
import { buttonClass } from "@/components/Button";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationCrumb, ModerationNav } from "@/components/ModerationNav";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { isUuid } from "@/lib/assets/queue";
import { MAP_PACK_BLURB_MAX, MAP_PACK_TITLE_MAX, fetchMapPack } from "@/lib/maps/packs";
import { createClient } from "@/lib/supabase/server";
import {
  addMapsToPack,
  deleteMapPack,
  removeMapFromPack,
  saveMapPack,
  setMapPackFeatured,
} from "../actions";

export const metadata: Metadata = {
  title: "Map pack - Moderation",
};

/**
 * One map pack: its title and blurb, whether coilbox shows it, and its maps
 * (tomjn/coilbox#3206).
 *
 * Maps go in by pasting a list, one per line, of map names or archive
 * filenames. A mirror's file list pastes straight in, which is how a pack of
 * every map on a mirror gets built without typing hundreds of names. Only names
 * the catalog holds are added, and the answer lists the lines that matched
 * nothing.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const BUTTON =
  buttonClass();

const SMALL =
  buttonClass("quiet", { size: "sm" });

/** Red, because nothing brings a deleted pack back. */
const DELETE =
  buttonClass("destructive", { size: "sm" });

export default async function MapPackPage({ params }: PageProps<"/moderation/map-packs/[id]">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page.
  if (!allowed) notFound();

  const { id } = await params;
  if (!isUuid(id)) notFound();
  const pack = await fetchMapPack(supabase, id);
  if (!pack) notFound();

  const featured = pack.featuredAt !== null;

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="mapPacks" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-1">
          <ModerationCrumb parent="mapPacks">{pack.title}</ModerationCrumb>
          <h1 className="text-3xl font-semibold tracking-tight">{pack.title}</h1>
        </div>

        <div className="flex items-center justify-between gap-3 rounded-md border border-neutral-800 bg-card px-5 py-3 text-sm">
          <span className="text-neutral-500">
            {featured
              ? "Featured, so Coilbox shows it in the Map packs menu."
              : "Not featured, so Coilbox does not show it."}
          </span>
          <VisibilityToggleForm
            action={setMapPackFeatured}
            fields={{ id: pack.id, featured: featured ? "false" : "true" }}
            label={featured ? "Unfeature" : "Feature"}
            pendingLabel={featured ? "Removing…" : "Featuring…"}
            formClassName="flex flex-wrap items-center justify-end gap-2"
            buttonClassName={buttonClass("ghost", { size: "md" })}
          />
        </div>

        <VisibilityToggleForm
          action={saveMapPack}
          fields={{ id: pack.id }}
          label="Save the title and blurb"
          pendingLabel="Saving…"
          formClassName="flex flex-col items-start gap-3"
          buttonClassName={BUTTON}
        >
          <label htmlFor="pack-title" className="text-sm text-neutral-400">
            Title
          </label>
          <input
            id="pack-title"
            name="title"
            required
            maxLength={MAP_PACK_TITLE_MAX}
            defaultValue={pack.title}
            className={`${INPUT} w-full`}
          />
          <label htmlFor="pack-blurb" className="text-sm text-neutral-400">
            Blurb, shown under the title in Coilbox. Optional.
          </label>
          <textarea
            id="pack-blurb"
            name="blurb"
            rows={2}
            maxLength={MAP_PACK_BLURB_MAX}
            defaultValue={pack.blurb ?? ""}
            className={`${INPUT} w-full`}
          />
        </VisibilityToggleForm>

        <VisibilityToggleForm
          action={addMapsToPack}
          fields={{ id: pack.id }}
          label="Add these maps"
          pendingLabel="Adding…"
          formClassName="flex flex-col items-start gap-3"
          buttonClassName={BUTTON}
        >
          <label htmlFor="pack-names" className="text-sm text-neutral-400">
            Map names or archive filenames, one per line. A mirror&apos;s file
            list pastes straight in. Case does not matter, and maps already in
            the pack stay where they are.
          </label>
          <textarea
            id="pack-names"
            name="names"
            rows={8}
            placeholder={"Comet Catcher Remake 1.8\nisis_1.3.sd7"}
            className={`${INPUT} w-full font-mono`}
          />
        </VisibilityToggleForm>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="pack-maps">
          <h2 id="pack-maps" className="text-sm uppercase tracking-wide text-neutral-400">
            {pack.maps.length === 1 ? "1 map" : `${pack.maps.length} maps`}
          </h2>

          {pack.maps.length === 0 ? (
            <p className="text-sm text-neutral-500">
              Nothing in this pack yet, so Coilbox will not show it even when it
              is featured.
            </p>
          ) : (
            <ul className="flex flex-col gap-1.5">
              {pack.maps.map((map) => (
                <li key={map.mapName} className="flex items-center justify-between gap-3 text-sm">
                  {map.slug ? (
                    <Link
                      href={`/moderation/maps/${map.slug}`}
                      className="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline"
                    >
                      {map.mapName}
                    </Link>
                  ) : (
                    // Still offered to Coilbox under its name, which is what it
                    // downloads by. Only the title and filename are missing.
                    <span className="min-w-0 break-words text-neutral-500">
                      {map.mapName} (not in the catalog right now)
                    </span>
                  )}
                  <VisibilityToggleForm
                    action={removeMapFromPack}
                    fields={{ id: pack.id, map: map.mapName }}
                    label="Remove"
                    pendingLabel="Removing…"
                    formClassName="flex shrink-0 items-center gap-2"
                    buttonClassName={SMALL}
                  />
                </li>
              ))}
            </ul>
          )}
        </section>

        <div className="flex justify-end border-t border-neutral-900 pt-6">
          <VisibilityToggleForm
            action={deleteMapPack}
            fields={{ id: pack.id }}
            label="Delete this pack"
            pendingLabel="Deleting…"
            formClassName="flex flex-col items-end gap-1.5"
            buttonClassName={DELETE}
          />
        </div>
      </div>
    </main>
  );
}
