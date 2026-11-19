-- What the mirror tables refuse and who can reach them, run as the roles
-- PostgREST uses.
--
-- The tables are closed to every browser role. The hub reads every mirror
-- table as service_role, archives only to copy them into MariaDB (#600), and
-- writes only through functions.

begin;
select plan(34);

create extension if not exists pgtap with schema extensions;

-- ## Grants

select table_privs_are('public', 'mirror', 'anon', ARRAY[]::name[], 'anon holds nothing on mirror');
select table_privs_are('public', 'mirror', 'authenticated', ARRAY[]::name[], 'authenticated holds nothing on mirror');
select table_privs_are('public', 'mirror', 'service_role', ARRAY['SELECT'], 'service_role can only select on mirror');

select table_privs_are('public', 'archive', 'anon', ARRAY[]::name[], 'anon holds nothing on archive');
select table_privs_are('public', 'archive', 'authenticated', ARRAY[]::name[], 'authenticated holds nothing on archive');
select table_privs_are('public', 'archive', 'service_role', ARRAY['SELECT'], 'service_role can only select on archive');

select table_privs_are('public', 'archive_mirror', 'anon', ARRAY[]::name[], 'anon holds nothing on archive_mirror');
select table_privs_are('public', 'archive_mirror', 'authenticated', ARRAY[]::name[], 'authenticated holds nothing on archive_mirror');
select table_privs_are('public', 'archive_mirror', 'service_role', ARRAY['SELECT'], 'service_role can only select on archive_mirror');

select table_privs_are('public', 'catalogue_state', 'anon', ARRAY[]::name[], 'anon holds nothing on catalogue_state');
select table_privs_are('public', 'catalogue_state', 'authenticated', ARRAY[]::name[], 'authenticated holds nothing on catalogue_state');
select table_privs_are('public', 'catalogue_state', 'service_role', ARRAY['SELECT'], 'service_role can only select on catalogue_state');

-- ## Browser roles are refused

set local role anon;
select throws_ok($$select 1 from public.mirror$$, '42501', null, 'anon cannot select from mirror');
select throws_ok($$select 1 from public.archive$$, '42501', null, 'anon cannot select from archive');
select throws_ok($$select 1 from public.archive_mirror$$, '42501', null, 'anon cannot select from archive_mirror');
select throws_ok($$select 1 from public.catalogue_state$$, '42501', null, 'anon cannot select from catalogue_state');
reset role;

set local role authenticated;
select throws_ok($$select 1 from public.mirror$$, '42501', null, 'authenticated cannot select from mirror');
select throws_ok($$select 1 from public.archive$$, '42501', null, 'authenticated cannot select from archive');
select throws_ok($$select 1 from public.archive_mirror$$, '42501', null, 'authenticated cannot select from archive_mirror');
select throws_ok($$select 1 from public.catalogue_state$$, '42501', null, 'authenticated cannot select from catalogue_state');
reset role;

-- ## jj-darkstars

select is(
  (select healthy::text || ' ' || enabled::text || ' ' || public_key
   from public.mirror where id = 'jj-darkstars'),
  'false true B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY',
  'jj-darkstars is registered, enabled and not yet healthy'
);

-- ## What the archive table refuses

insert into public.archive (id, sha256, filename, kind, internal_name, version, size)
values (
  '11111111-0000-4000-8000-000000000001',
  repeat('a', 64), 'map.sd7', 'map', 'Test Map 1.0', '1.0', 1000
);

select throws_ok(
  $$insert into public.archive (sha256, filename, kind, internal_name, version, size)
    values (repeat('a', 64), 'other.sd7', 'map', 'Other 1.0', '1.0', 1000)$$,
  '23505', null,
  'a second archive with the same sha256 is refused'
);

select throws_ok(
  $$insert into public.archive (sha256, filename, kind, internal_name, version, size)
    values (repeat('b', 64), 'Map.sd7', 'map', 'Other 1.0', '1.0', 1000)$$,
  '23505', null,
  'a filename differing only in case is refused'
);

select throws_ok(
  $$insert into public.archive (sha256, filename, kind, internal_name, version, size)
    values (repeat('b', 64), 'other.sd7', 'map', 'Test Map 1.0', '1.0', 1000)$$,
  '23505', null,
  'the same internal name and version is refused'
);

select throws_ok(
  $$insert into public.archive (sha256, filename, kind, internal_name, version, shortname, size)
    values (repeat('b', 64), 'game.sd7', 'game', 'Test Game', '1', null, 1000)$$,
  '23514', null,
  'a game archive without a shortname is refused'
);

update public.archive set state = 'failed'
where id = '11111111-0000-4000-8000-000000000001';

select lives_ok(
  $$insert into public.archive (sha256, filename, kind, internal_name, version, size)
    values (repeat('a', 64), 'map.sd7', 'map', 'Test Map 1.0', '1.0', 1000)$$,
  'the same hash, name and map name are free again once the first failed'
);

-- ## The change counter

create temporary table changes_seen (label text primary key, n bigint);
grant all on changes_seen to public;

insert into changes_seen select 'start', changes from public.catalogue_state;

update public.archive set state = 'listed', listed_at = now()
where sha256 = repeat('a', 64) and state = 'uploading';

select is(
  (select changes from public.catalogue_state) - (select n from changes_seen where label = 'start'),
  1::bigint,
  'listing an archive raises the change count by one'
);

insert into public.archive (id, sha256, filename, kind, internal_name, version, size)
values ('11111111-0000-4000-8000-000000000002', repeat('c', 64), 'c.sd7', 'map', 'C 1.0', '1.0', 10);

insert into changes_seen select 'before_fail', changes from public.catalogue_state;

update public.archive set state = 'failed' where id = '11111111-0000-4000-8000-000000000002';

select is(
  (select changes from public.catalogue_state) - (select n from changes_seen where label = 'before_fail'),
  0::bigint,
  'failing an upload leaves the change count alone'
);

insert into changes_seen select 'before_health', changes from public.catalogue_state;

update public.mirror set healthy = true where id = 'jj-darkstars';

select is(
  (select changes from public.catalogue_state) - (select n from changes_seen where label = 'before_health'),
  0::bigint,
  'a health change leaves the change count alone'
);

update public.mirror set base_url = 'https://jj.darkstars.co.uk/files' where id = 'jj-darkstars';

select is(
  (select changes from public.catalogue_state) - (select n from changes_seen where label = 'before_health'),
  1::bigint,
  'changing a mirror''s base_url raises the change count by one'
);

-- ## The state row

select is((select count(*) from public.catalogue_state), 1::bigint, 'catalogue_state holds exactly one row');

select throws_ok(
  $$insert into public.catalogue_state (id) values (false)$$,
  '23514', null,
  'a second catalogue_state row is refused'
);

select throws_ok(
  $$insert into public.mirror (id, name, base_url, public_key)
    values ('Bad ID', 'x', 'https://x.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY')$$,
  '23514', null,
  'a mirror ID outside the rule is refused'
);

select throws_ok(
  $$insert into public.mirror (id, name, base_url, public_key)
    values ('x', 'x', 'https://x.test/', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY')$$,
  '23514', null,
  'a base_url ending in a slash is refused'
);

select * from finish();
rollback;
