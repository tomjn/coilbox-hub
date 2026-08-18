import type { MapMirrorLink } from "@/lib/maps/mirrors";

/**
 * Where to look for a map's archive (#192).
 *
 * Nothing at all when there is nowhere to send the reader, the same as
 * `MapPlayedOn`. A host can be added, corrected or turned off at any time, so a
 * page with no links is an ordinary page and a heading over an empty list would
 * be the hub advertising a gap in its own configuration.
 *
 * ## Look for, not download
 *
 * The links are built from a template and a filename, and nothing has asked the
 * host whether it holds this map. `20260819100000_map_mirror_seed.sql` says why
 * nothing is going to: finding out means one request per map per host against
 * somebody else's server.
 *
 * So the words have to survive being wrong. "Look for it on hakora" is true
 * whatever hakora turns out to have. "Download from hakora" is a promise the hub
 * cannot keep, and the reader finds that out after the click, on somebody else's
 * 404 page.
 */
export function MapMirrors({ links }: { links: MapMirrorLink[] }) {
  if (links.length === 0) return null;

  return (
    <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
      <h2 className="text-xl font-semibold tracking-tight">Where to look for this map</h2>
      <p className="text-sm text-neutral-500">
        The hub holds facts about maps, not the maps themselves. These sites host map archives
        and may have this one.
      </p>
      <ul className="flex flex-wrap gap-3">
        {links.map((link) => (
          <li key={link.name}>
            <a
              // Some hosts are http only, which a browser allows for a
              // navigation and blocks for a subresource. The seed migration
              // names which and why.
              href={link.url}
              target="_blank"
              rel="noreferrer"
              className="inline-block rounded bg-neutral-900 px-3 py-2 text-sm text-neutral-300 transition-colors hover:text-white"
            >
              Look for it on {link.name}
            </a>
          </li>
        ))}
      </ul>
    </section>
  );
}
