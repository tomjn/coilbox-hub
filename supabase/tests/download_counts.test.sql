-- Anonymous download counters. record_download counts one download per
-- target per anonymous key per day, and the browse view reads the totals.
-- Calls run as service_role, the only role with execute, and counts are read
-- back as the owner because download_day and download_seen have no grants.

begin;
select plan(23);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('55555555-5555-5555-5555-555555555555', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'downloads@example.test');

insert into public.map (
  map_name, slug,
  width_elmos, height_elmos, world_height_min, world_height_max,
  curated_tags,
  source_hash, source_archive, catalog_version, facts_digest
)
values ('Counted 1.0', 'counted', 4096, 4096, 0, 100, '{}', 'src-c', 'c.sd7', 1, 'digest-c');

insert into public.item (id, kind, kind_version, title, container, author_id, author_name, deleted_at)
values
  ('dddddddd-0000-0000-0000-000000000001', 'mod-project', 1, 'Live',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{}}',
   '55555555-5555-5555-5555-555555555555', 'Downloads', null),
  ('dddddddd-0000-0000-0000-000000000002', 'mod-project', 1, 'Withdrawn',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{}}',
   '55555555-5555-5555-5555-555555555555', 'Downloads', now());

insert into public.game (id, shortname, hidden_at)
values
  ('dddddddd-0000-0000-0000-0000000000a1', 'SHOWN', null),
  ('dddddddd-0000-0000-0000-0000000000a2', 'SECRET', now());

insert into public.download_seen (day, seen_key)
values (current_date - 2, repeat('e', 64));

set local role service_role;

select is(public.record_download('map', 'Counted 1.0', repeat('a', 64)), true,
  'a first download of a map is recorded');

reset role;
select is(
  (select count from public.download_day
   where kind = 'map' and day = current_date
     and target_id = (select id from public.map where map_name = 'Counted 1.0')),
  1, 'today has one download for the map');

set local role service_role;
select is(public.record_download('map', 'Counted 1.0', repeat('a', 64)), true,
  'the same key downloading again still reports success');

reset role;
select is(
  (select count from public.download_day
   where kind = 'map' and day = current_date
     and target_id = (select id from public.map where map_name = 'Counted 1.0')),
  1, 'the same key on the same day is counted once');

set local role service_role;
select record_download('map', 'Counted 1.0', repeat('b', 64));

reset role;
select is(
  (select count from public.download_day
   where kind = 'map' and day = current_date
     and target_id = (select id from public.map where map_name = 'Counted 1.0')),
  2, 'a different key makes it two');

set local role service_role;
select is(public.record_download('map', 'No such map', repeat('c', 64)), false,
  'an unknown map name is not recorded');
select is(public.record_download('game', 'nosuchgame', repeat('c', 64)), false,
  'an unknown game shortname is not recorded');
select is(public.record_download('game', 'SECRET', repeat('c', 64)), false,
  'a hidden game is not recorded');
select is(public.record_download('game', 'SHOWN', repeat('f', 64)), true,
  'a visible game is recorded');
select is(public.record_download('item', 'dddddddd-0000-0000-0000-000000000002', repeat('c', 64)), false,
  'a withdrawn item is not recorded');
select is(public.record_download('item', 'dddddddd-0000-0000-0000-000000000001', repeat('c', 64)), true,
  'a live item is recorded');

reset role;
select is(
  (select import_count from public.item where id = 'dddddddd-0000-0000-0000-000000000001'),
  1, 'an item download adds one to import_count');
select is(
  (select count from public.download_day
   where kind = 'item' and day = current_date
     and target_id = 'dddddddd-0000-0000-0000-000000000001'),
  1, 'an item download adds one to download_day');
select is(
  (select count(*)::integer from public.download_seen where day = current_date - 2),
  0, 'a seen key from two days ago is cleared by a call');

set local role anon;
select is(
  (select downloads_30d from public.map_browse where slug = 'counted'),
  2::bigint, 'map_browse shows the recent downloads');

reset role;
insert into public.download_day (kind, target_id, day, count)
select 'map', id, current_date - 40, 1 from public.map where map_name = 'Counted 1.0';

set local role anon;
select is(
  (select downloads_30d from public.map_browse where slug = 'counted'),
  2::bigint, 'a download 40 days ago is outside the 30 day window');
select is(
  (select downloads_182d from public.map_browse where slug = 'counted'),
  3::bigint, 'a download 40 days ago is inside the 182 day window');
select is(
  (select downloads_total from public.map_browse where slug = 'counted'),
  3::bigint, 'the total includes every day');

select throws_ok(
  $$select * from public.download_seen$$,
  '42501',
  null,
  'anon cannot read download_seen');

select throws_ok(
  $$select public.record_download('map', 'Counted 1.0', repeat('d', 64))$$,
  '42501',
  null,
  'anon cannot call record_download');

select throws_ok(
  $$select public.record_item_imported('dddddddd-0000-0000-0000-000000000001')$$,
  '42501',
  null,
  'anon cannot call record_item_imported');

set local role authenticated;
select throws_ok(
  $$select public.record_download('map', 'Counted 1.0', repeat('d', 64))$$,
  '42501',
  null,
  'authenticated cannot call record_download');

reset role;
select is(
  has_function_privilege('service_role', 'public.record_download(text, text, text)', 'execute'),
  true, 'service_role can execute record_download');

select * from finish();
rollback;
