import type { Metadata } from "next";
import Link from "next/link";
import { buttonClass } from "@/components/Button";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { MAP_PACK_TITLE_MAX, fetchMapPacks } from "@/lib/maps/packs";
import { createClient } from "@/lib/supabase/server";
import { createMapPack } from "./actions";

export const metadata: Metadata = {
  title: "Map packs - Moderation",
};

/**
 * Every map pack, and the form that starts a new one (tomjn/coilbox#3206).
 *
 * A featured pack is listed by `GET /api/v1/map-packs` and shows in coilbox's
 * Map packs menu. A pack is built on its own page, by pasting a list of map
 * names or archive filenames.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-none";

const BUTTON =
  buttonClass();

function mapCount(count: number): string {
  return count === 1 ? "1 map" : `${count} maps`;
}

export default async function MapPacks() {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page.
  if (!allowed) notFound();

  const { packs, error } = await fetchMapPacks(supabase);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="mapPacks" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Map packs</h1>

        <p className="text-sm text-neutral-500">
          A featured pack shows in the Map packs menu on Coilbox&apos;s Downloads
          page, where a player can download every map in it at once. A pack
          with no maps in it is never shown.
        </p>

        <VisibilityToggleForm
          action={createMapPack}
          fields={{}}
          label="Start a pack"
          pendingLabel="Starting…"
          formClassName="flex flex-wrap items-center gap-3"
          buttonClassName={BUTTON}
        >
          <label className="sr-only" htmlFor="new-pack-title">
            Title of the new pack
          </label>
          <input
            id="new-pack-title"
            name="title"
            required
            maxLength={MAP_PACK_TITLE_MAX}
            placeholder="Title of the new pack"
            className={`${INPUT} flex-1`}
          />
        </VisibilityToggleForm>

        {error ? (
          <p role="alert" className="text-sm text-red-400">
            The packs could not be read just now.
          </p>
        ) : packs.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">
            There are no map packs yet.
          </p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {packs.map((pack) => (
              <li key={pack.id} className="flex items-baseline justify-between gap-3 text-sm">
                <Link
                  href={`/moderation/map-packs/${pack.id}`}
                  className="min-w-0 break-words text-neutral-300 underline-offset-4 hover:underline active:underline"
                >
                  {pack.title}
                </Link>
                <span className="shrink-0 text-xs text-neutral-500">
                  {mapCount(pack.maps.length)}
                  {pack.featuredAt ? ", featured" : ", not featured"}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
