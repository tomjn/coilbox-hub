-- The hosts a map page offers (issue #192).
--
-- A seed that inserted nothing fails silently. The page renders a section only
-- when it has links, so an empty table and a working table look the same from
-- the outside: no section, no error, nothing in a log. So the rows are asserted
-- here.
--
-- The templates are asserted for their placeholders as well as their text.
-- lib/maps/mirrors.ts fills in {springname} and {filename}, and a template
-- naming the column instead, {archive_filename}, is the mistake this catches: it
-- renders as itself, links every map to the same directory listing, and nothing
-- raises.
--
-- The grants are not tested here. table_privileges.test.sql asserts them
-- directly and map_access.test.sql covers what a visitor may read.

begin;
select plan(10);

create extension if not exists pgtap with schema extensions;

select is(
  (select count(*) from public.map_mirror_host)::int, 2,
  'the catalog ships with the two hosts coilbox already downloads maps through'
);

-- ## hakora

select is(
  (select url_template from public.map_mirror_host where name = 'hakora'),
  'http://hakora.xyz/files/springrts/maps/{filename}',
  'hakora is its archive directory plus the filename, which is all that listing needs'
);

select is(
  (select enabled from public.map_mirror_host where name = 'hakora'), true,
  'and it is offered, since it is the one host a URL can be built for today'
);

-- The reader is sent to an http URL from an https page. Browsers allow the
-- navigation and block a subresource, so nothing breaks and nothing warns, and
-- the note is where somebody looking at it as a bug finds that out.
select matches(
  (select note from public.map_mirror_host where name = 'hakora'),
  'http only',
  'and it says outright that it is http, linked from an https page'
);

-- ## springfiles

select is(
  (select enabled from public.map_mirror_host where name = 'springfiles'), false,
  'springfiles is off, because the only URL coilbox proves answers JSON'
);

select isnt(
  (select note from public.map_mirror_host where name = 'springfiles'), null,
  'and carries what would have to be confirmed before somebody turns it on'
);

-- ## Templates

-- The renderer fills in {springname} and {filename} and nothing else. A template
-- naming the column renders as itself and sends every map to one URL.
select is(
  (select count(*) from public.map_mirror_host
    where url_template like '%{archive\_filename}%')::int,
  0,
  'no template asks for a placeholder the renderer has never heard of'
);

select is(
  (select count(*) from public.map_mirror_host
    where url_template not like '%{springname}%' and url_template not like '%{filename}%')::int,
  0,
  'and every template asks for something about the map, rather than one URL for all of them'
);

-- ## Ordering

select is(
  (select name from public.map_mirror_host
    where enabled order by sort_order limit 1),
  'hakora',
  'the enabled hosts come back in the order a maintainer set, best first'
);

-- Off rather than deleted, which is what makes turning springfiles on one column
-- rather than a migration.
select is(
  (select count(*) from public.map_mirror_host where enabled)::int, 1,
  'and a host that is off is still a row, so enabling it is an update'
);

select * from finish();
rollback;
