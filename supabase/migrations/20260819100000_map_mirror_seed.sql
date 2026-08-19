-- The two hosts a person can go to for a map archive (issue #192).
--
-- public.map_mirror_host has been empty since 20260818100000 wrote it. These
-- rows are the hosts coilbox already resolves map downloads through, read out
-- of crates/tauri-plugin-coilbox-downloads/src/sources.rs on 2026-08-19, so the
-- hub points at what the client points at rather than at a mirror somebody
-- remembered.
--
-- The template takes {springname}, the canonical map name, and {filename}, the
-- archive_filename on public.map. lib/maps/mirrors.ts renders them and URL
-- encodes both, and a template that needs a filename the map has no value for
-- produces no link at all. The column takes any text, for the reason its own
-- comment gives: the placeholders belong to the code that renders them, and a
-- check here would be a second copy of that convention quietly drifting from
-- the first.
--
-- on conflict do nothing, so rebuilding a database where a maintainer has
-- corrected a template or turned a host off does not put these back.
--
-- ## hakora is http, linked from an https page
--
-- HAKORA_MAPS_URL is http and that host serves nothing over https. A browser
-- blocks a mixed content subresource and allows a mixed content navigation, so
-- the link works and nothing warns. It is worth knowing before somebody reports
-- it as a bug and goes looking for the mistake.
--
-- ## springfiles is seeded off
--
-- The only springfiles URL proven anywhere in coilbox is the json.php endpoint
-- its catalog sync reads, which answers JSON. That is a poor thing to hand a
-- person, so the row is off and the page shows one mirror instead of two.
--
-- ## Nothing checks whether a host holds a given map
--
-- Two ways of finding out were considered and rejected, and a maintainer
-- looking at a short mirror list will think of both.
--
-- A scheduled job could HEAD every candidate URL and link only the ones that
-- answer. It would also be one request per map per host against somebody else's
-- server, which is the load coilbox has just finished taking off BAR's
-- infrastructure. Doing it to hakora instead is the same act.
--
-- Coilbox could report the URL it actually downloaded a map from, since it
-- resolves through these hosts already. That is worth doing later. It only ever
-- covers maps somebody fetched through coilbox, and it puts a column on the
-- ingest payload to answer a question one template per host answers for
-- nothing.
--
-- So the link says where to look rather than promising a file, which is a
-- wording decision rather than a data one, and lib/maps/mirrors.ts holds it.

insert into public.map_mirror_host (name, url_template, enabled, sort_order, note)
values
  (
    'hakora',
    'http://hakora.xyz/files/springrts/maps/{filename}',
    true,
    0,
    $note$HAKORA_MAPS_URL in coilbox, at crates/tauri-plugin-coilbox-downloads/src/sources.rs:154, plus the archive filename. An Apache directory listing of .sd7 and .sdz archives, which is what makes the filename enough to build a URL.

http only. There is no https on this host, so a map page served over https links out to http. Browsers allow that for a navigation and block it for a subresource, so nothing breaks and nothing warns.$note$
  ),
  (
    'springfiles',
    'https://springfiles.springrts.com/json.php?springname={springname}&category=*map*&images=on&metadata=1',
    false,
    10,
    $note$Off until somebody confirms a human facing search URL on this host. Enabling it is then two columns: the template and the flag.

The template here is springfiles_list_url from coilbox, at crates/tauri-plugin-coilbox-downloads/src/sources.rs:147, with one map name in place of the wildcard. Two things are wrong with handing it to a reader. It answers JSON, which is not a page. And the substitution is this hub's guess at a per map query, where the endpoint itself is the only part coilbox proves.

What would settle it is the URL a person gets when they search springfiles in a browser. Check that it names the map in the query string, that it answers HTML, and that the canonical name is what it wants rather than a name with the version stripped off.$note$
  )
on conflict do nothing;
