import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Where a reader can go for a map archive the hub does not hold (#192).
 *
 * The hub holds facts about maps and no archives, and it is not going to start,
 * so the answer to "where do I get this" is somebody else's server.
 * `public.map_mirror_host` holds those servers as rows rather than as a constant
 * here, which is what makes a mirror going down a column and not a deploy.
 *
 * ## The link says where to look, and never promises a file
 *
 * A template builds a URL out of a map's name and filename. It cannot know
 * whether that host actually holds that map, and nothing here finds out:
 * `20260819100000_map_mirror_seed.sql` sets out why a checker job crawling
 * somebody else's server once per map per host was rejected.
 *
 * So the wording is the honest half of that decision. "Look for it on hakora"
 * is true whatever the host turns out to hold. A download link that 404s is a
 * promise the hub had no way to keep, and the reader finds out after the click.
 *
 * ## Both placeholders are URL encoded
 *
 * `{springname}` is the canonical map name and `{filename}` is
 * `public.map.archive_filename`. Map names carry spaces, brackets and accents as
 * a matter of course, so an unencoded one produces a URL that is broken in a way
 * that looks like the mirror's fault.
 */

/** The columns a link needs, which is every column except the note and the
 *  ordering the query already applied. */
export interface MapMirrorHost {
  name: string;
  url_template: string;
  enabled: boolean;
}

export interface MapMirrorLink {
  name: string;
  url: string;
}

/** What a map brings to a template. `archiveFilename` is null on plenty of
 *  rows, since a map can be catalogued from an archive nobody recorded the name
 *  of. */
export interface MapMirrorSubject {
  mapName: string;
  archiveFilename: string | null;
}

const SPRINGNAME = "{springname}";
const FILENAME = "{filename}";

/**
 * One host's URL for one map, or null when the template asks for something the
 * map has no value for.
 *
 * Null rather than a URL with an empty filename in it. A template needing
 * `{filename}` on a map with no `archive_filename` would otherwise render a link
 * to the host's directory listing under the words "look for it here", which
 * sends the reader somewhere real that is not about their map.
 *
 * A template with neither placeholder renders as itself. That is a host with one
 * page for everything, which is a strange thing to seed and not a broken one.
 */
export function mirrorUrl(template: string, map: MapMirrorSubject): string | null {
  const filename = map.archiveFilename?.trim() ?? "";
  if (template.includes(FILENAME) && filename === "") return null;

  return template
    .replaceAll(SPRINGNAME, encodeURIComponent(map.mapName))
    .replaceAll(FILENAME, encodeURIComponent(filename));
}

/**
 * Every link one map has, in the order the hosts were given in.
 *
 * Disabled hosts are dropped here as well as in the query. The query filters so
 * the database does not send rows nothing will draw, and this filters because
 * "only an enabled host gets a link" is the rule and a caller reading the table
 * some other way must not be able to skip it.
 */
export function mirrorLinks(
  hosts: MapMirrorHost[],
  map: MapMirrorSubject,
): MapMirrorLink[] {
  return hosts
    .filter((host) => host.enabled)
    .flatMap((host) => {
      const url = mirrorUrl(host.url_template, map);
      return url === null ? [] : [{ name: host.name, url }];
    });
}

/**
 * The hosts to offer, best first.
 *
 * Read with the visitor's own client. `anon` holds select on
 * `public.map_mirror_host` and every column read here is meant to be published,
 * so there is nothing the secret key would answer differently.
 *
 * An error answers no hosts, which renders no section. The page is about the map
 * and a mirror list that could not be read is a page with one part missing, not
 * a page worth withholding.
 */
export async function fetchMirrorHosts(supabase: SupabaseClient): Promise<MapMirrorHost[]> {
  const { data } = await supabase
    .from("map_mirror_host")
    .select("name, url_template, enabled")
    .eq("enabled", true)
    .order("sort_order", { ascending: true });

  return (data ?? []) as MapMirrorHost[];
}
