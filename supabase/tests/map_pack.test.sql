-- Map packs (tomjn/coilbox#3206): who may read and write them, and what
-- public.add_maps_to_pack does with a pasted list.

begin;
select plan(23);

create extension if not exists pgtap with schema extensions;

-- ## Grants

select table_privs_are('public', 'map_pack', 'anon', ARRAY['SELECT'],
  'anon can only select on map_pack');
select table_privs_are('public', 'map_pack', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_pack');
select table_privs_are('public', 'map_pack', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
  'service_role writes map_pack for the moderation page');
select table_privs_are('public', 'map_pack_map', 'anon', ARRAY['SELECT'],
  'anon can only select on map_pack_map');
select table_privs_are('public', 'map_pack_map', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_pack_map');
select table_privs_are('public', 'map_pack_map', 'service_role',
  ARRAY['SELECT', 'INSERT', 'DELETE'],
  'service_role adds and removes entries, and never edits one in place');
select table_privs_are('public', 'map_pack_entry', 'anon', ARRAY['SELECT'],
  'anon can only select on map_pack_entry');
select function_privs_are('public', 'add_maps_to_pack', ARRAY['uuid', 'text[]'],
  'anon', ARRAY[]::name[], 'anon cannot add maps to a pack');
select function_privs_are('public', 'add_maps_to_pack', ARRAY['uuid', 'text[]'],
  'authenticated', ARRAY[]::name[], 'authenticated cannot add maps to a pack');
select function_privs_are('public', 'add_maps_to_pack', ARRAY['uuid', 'text[]'],
  'service_role', ARRAY['EXECUTE'], 'service_role can add maps to a pack');

-- ## Fixtures

insert into auth.users (id, instance_id, aud, role, email)
values ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test');

insert into public.map (
  id, map_name, slug, display_name, archive_filename,
  width_elmos, height_elmos, world_height_min, world_height_max,
  source_hash, source_archive, catalog_version, facts_digest
)
values
  ('0f8fad5b-0007-4000-8000-000000000001', 'Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8', 'Comet Catcher',
   'comet_catcher_remake_1.8.sd7', 6144, 10240, -120.5, 890, 'src-comet', 'comet_catcher_remake_1.8.sd7', 1, 'digest-comet'),
  ('0f8fad5b-0007-4000-8000-000000000002', 'Isis 1.3', 'isis-1-3', null,
   'isis_1.3.sd7', 8192, 8192, 0, 500, 'src-isis', 'isis_1.3.sd7', 1, 'digest-isis');

insert into public.map_pack (id, title, blurb)
values ('9e0f0000-0000-4000-8000-000000000001', 'BAR maps', 'Every map on the mirror');

-- ## What anon and authenticated cannot do

set local role anon;
select throws_ok(
  $$insert into public.map_pack (title) values ('Mine')$$,
  '42501', null, 'anon cannot create a pack');
select throws_ok(
  $$select * from public.add_maps_to_pack('9e0f0000-0000-4000-8000-000000000001', ARRAY['Isis 1.3'])$$,
  '42501', null, 'anon cannot call add_maps_to_pack');

reset role;
set local role authenticated;
set local request.jwt.claims to '{"sub": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa", "role": "authenticated"}';
select throws_ok(
  $$insert into public.map_pack_map (pack_id, map_name, position) values ('9e0f0000-0000-4000-8000-000000000001', 'Isis 1.3', 0)$$,
  '42501', null, 'a signed in stranger cannot add a map to a pack');
select throws_ok(
  $$update public.map_pack set featured_at = now()$$,
  '42501', null, 'a signed in stranger cannot feature a pack');

-- ## Adding a pasted list, as the moderation page does

reset role;
set local role service_role;

select results_eq(
  $$select wanted, map_name from public.add_maps_to_pack(
      '9e0f0000-0000-4000-8000-000000000001',
      ARRAY['isis_1.3.SD7', '  comet catcher remake 1.8 ', '', 'Nowhere 9', 'Isis 1.3'])$$,
  $$values
      ('isis_1.3.SD7', 'Isis 1.3'),
      ('comet catcher remake 1.8', 'Comet Catcher Remake 1.8'),
      ('Nowhere 9', null::text),
      ('Isis 1.3', 'Isis 1.3')$$,
  'every non-blank line comes back, matched by filename or name regardless of case, or null');

select results_eq(
  $$select map_name, position from public.map_pack_map
    where pack_id = '9e0f0000-0000-4000-8000-000000000001' order by position$$,
  $$values ('Isis 1.3', 0), ('Comet Catcher Remake 1.8', 1)$$,
  'each matched map is stored once, under its catalog name, in pasted order');

select results_eq(
  $$select wanted, map_name from public.add_maps_to_pack(
      '9e0f0000-0000-4000-8000-000000000001', ARRAY['Comet Catcher Remake 1.8'])$$,
  $$values ('Comet Catcher Remake 1.8', 'Comet Catcher Remake 1.8')$$,
  'a map already in the pack still reports its match');

select is(
  (select count(*)::integer from public.map_pack_map where pack_id = '9e0f0000-0000-4000-8000-000000000001'),
  2, 'adding a map already in the pack does not add it twice');

-- ## The read the route makes

reset role;
set local role anon;

select results_eq(
  $$select map_name, display_name, archive_filename from public.map_pack_entry
    where pack_id = '9e0f0000-0000-4000-8000-000000000001' order by position$$,
  $$values
      ('Isis 1.3', null::text, 'isis_1.3.sd7'),
      ('Comet Catcher Remake 1.8', 'Comet Catcher', 'comet_catcher_remake_1.8.sd7')$$,
  'anon reads each entry with the catalog facts joined in');

select is(
  (select title from public.map_pack where id = '9e0f0000-0000-4000-8000-000000000001'),
  'BAR maps', 'anon reads the pack itself');

-- A cleared map drops out of the catalog, and its entry stays in the pack.
reset role;
delete from public.map where id = '0f8fad5b-0007-4000-8000-000000000002';
set local role anon;

select results_eq(
  $$select map_name, slug from public.map_pack_entry
    where pack_id = '9e0f0000-0000-4000-8000-000000000001' order by position$$,
  $$values ('Isis 1.3', null::text), ('Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8')$$,
  'an entry whose map left the catalog is still listed, with no catalog facts');

-- ## Deleting a pack takes its entries with it

reset role;
set local role service_role;
delete from public.map_pack where id = '9e0f0000-0000-4000-8000-000000000001';

select is(
  (select count(*)::integer from public.map_pack_map),
  0, 'deleting a pack deletes its entries');

select throws_ok(
  $$select * from public.add_maps_to_pack('9e0f0000-0000-4000-8000-000000000001', ARRAY['Comet Catcher Remake 1.8'])$$,
  '23503', null, 'adding to a pack that does not exist is refused');

select * from finish();
rollback;
