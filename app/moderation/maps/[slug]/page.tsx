import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationCrumb, ModerationNav } from "@/components/ModerationNav";
import {
  CURATED_TAG_LIMIT,
  curatedTagsField,
  fetchModeratedMap,
} from "@/lib/maps/moderation";
import { createClient } from "@/lib/supabase/server";
import { saveCuratedTags } from "../actions";

/**
 * The tags on one map that no measurement produces (issue #193).
 *
 * `public.map_listing` works five tags out of what the extractor measured: the
 * size band, whether there is water, whether wind or tide is worth building.
 * What it cannot produce is `asymmetric`, `1v1` or `chokepoint`, which are
 * things a person knows about a map by playing it. `map.curated_tags` is where
 * those go, and this form is the only thing that writes the column.
 *
 * The archive's own words are in `description`, and the two are kept apart on
 * purpose: mixing them would make a curated listing rewritable by whoever
 * packaged the map. Ingest never writes this column either, so nothing a client
 * sends can undo the work, which `supabase/tests/map_moderation.test.sql`
 * proves against a real re-ingest at a newer catalog version.
 *
 * The derived tags are shown and not editable, because there is nothing to edit:
 * they are recomputed from the measurements on every read, and a value typed
 * over one would last until the next request.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-none";

const BUTTON =
  "rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition-colors hover:border-neutral-500 active:border-neutral-400 hover:text-white active:text-white";

export default async function MapCuratedTags({
  params,
}: PageProps<"/moderation/maps/[slug]">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page.
  if (!allowed) notFound();

  const { slug } = await params;
  const map = await fetchModeratedMap(supabase, slug);
  if (!map) notFound();

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="maps" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <div className="flex flex-col gap-1">
          <ModerationCrumb parent="maps">
            {map.displayName ?? map.mapName}
          </ModerationCrumb>
          <div className="flex flex-wrap items-baseline justify-between gap-3">
            <h1 className="text-3xl font-semibold tracking-tight">
              {map.displayName ?? map.mapName}
            </h1>
            {/* The public page for the same map, which is the only link here
                that leaves moderation. */}
            <Link
              href={`/map/${map.slug}`}
              className="text-sm text-neutral-500 transition-colors hover:text-neutral-300 active:text-neutral-300"
            >
              The map
            </Link>
          </div>
        </div>

        <p className="text-sm text-neutral-500">{map.mapName}</p>

        <form action={saveCuratedTags} className="flex flex-col gap-3">
          <input type="hidden" name="map" value={map.id} />
          <input type="hidden" name="slug" value={map.slug} />

          <label htmlFor="curated-tags" className="text-sm text-neutral-400">
            Tags for what no measurement captures, separated by commas. Up to{" "}
            {CURATED_TAG_LIMIT} of them, and the whole box is the answer, so
            deleting a word here takes the tag off the map.
          </label>
          <input
            id="curated-tags"
            name="tags"
            defaultValue={curatedTagsField(map.curatedTags)}
            placeholder="asymmetric, 1v1, chokepoint"
            className={INPUT}
          />

          <div>
            <button type="submit" className={BUTTON}>
              Save the tags
            </button>
          </div>
        </form>

        <p className="text-sm text-neutral-500">
          {map.derivedTags.length === 0
            ? "Nothing was measured that produces a tag of its own."
            : `Worked out from the measurements, and not editable: ${map.derivedTags.join(", ")}.`}
        </p>
      </div>
    </main>
  );
}
