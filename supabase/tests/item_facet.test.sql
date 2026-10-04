-- The gallery's filter chips (issue #459): each visible game and map once, and
-- nothing a visitor cannot see.

begin;
select plan(8);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'facet@example.test');

-- Game keys and map names that no other fixture or seeded row uses, so the
-- assertions hold on a database where somebody has published through the app.
-- Two live items share one game and one map, one is live with a game and map of
-- its own, one is withdrawn with a game and map of its own, one is withdrawn and
-- shares a game and map with a live item, and two carry nothing usable.
insert into public.item (id, kind, kind_version, title, container, author_id, author_name, game_key, map_name, deleted_at)
values
  ('bbbbbbbb-0000-0000-0000-000000000001', 'preset', 1, 'Shared one', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-shared', 'Facet Map Shared', null),
  ('bbbbbbbb-0000-0000-0000-000000000002', 'preset', 1, 'Shared two', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-shared', 'Facet Map Shared', null),
  ('bbbbbbbb-0000-0000-0000-000000000003', 'preset', 1, 'Live own', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-live', 'Facet Map Live', null),
  ('bbbbbbbb-0000-0000-0000-000000000004', 'preset', 1, 'Withdrawn own', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-gone', 'Facet Map Gone', now()),
  ('bbbbbbbb-0000-0000-0000-000000000005', 'preset', 1, 'Withdrawn shared', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-live', 'Facet Map Live', now()),
  ('bbbbbbbb-0000-0000-0000-000000000006', 'preset', 1, 'Nothing', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', null, null, null),
  ('bbbbbbbb-0000-0000-0000-000000000007', 'preset', 1, 'Empty', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', '', '', null);

select table_privs_are('public', 'item_facet', 'anon', ARRAY['SELECT'],
  'anon can only select on item_facet');
select table_privs_are('public', 'item_facet', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on item_facet');

set local role anon;

select results_eq(
  $$select value from public.item_facet where facet = 'game' and value like 'facet-game-%' order by value$$,
  $$values ('facet-game-live'), ('facet-game-shared')$$,
  'anon sees each live game once, with none from a withdrawn item alone');

select results_eq(
  $$select value from public.item_facet where facet = 'map' and value like 'Facet Map %' order by value$$,
  $$values ('Facet Map Live'), ('Facet Map Shared')$$,
  'anon sees each live map once, with none from a withdrawn item alone');

select is(
  (select count(*)::integer from public.item_facet where value is null or value = ''),
  0, 'null and empty values are not facets');

-- The author's own client can read their withdrawn items from public.item, and
-- the view must still leave them out.
reset role;
set local role authenticated;
set local request.jwt.claims to '{"sub": "33333333-3333-3333-3333-333333333333", "role": "authenticated"}';

select is(
  (select count(*)::integer from public.item where id = 'bbbbbbbb-0000-0000-0000-000000000004'),
  1, 'the author can read their withdrawn item from item');

select is(
  (select count(*)::integer from public.item_facet where value in ('facet-game-gone', 'Facet Map Gone')),
  0, 'the author sees no facet from their own withdrawn item');

-- The thousand row fault: far more items than the old sample, one game and map
-- at the very end of the table that a limit of 1000 in no order could miss.
-- The bulk rows are dated two hours back so the publish rate limit, which counts
-- an author's items from the last hour, does not refuse them.
reset role;
insert into public.item (kind, kind_version, title, container, author_id, author_name, game_key, map_name, created_at)
select 'preset', 1, 'Bulk ' || n, '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-bulk-' || (n % 5), 'Facet Bulk Map', now() - interval '2 hours'
from generate_series(1, 1500) as n;
insert into public.item (kind, kind_version, title, container, author_id, author_name, game_key, map_name)
values ('preset', 1, 'Last', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '33333333-3333-3333-3333-333333333333', 'Facet', 'facet-game-last', 'Facet Map Last');

set local role anon;
select results_eq(
  $$select value from public.item_facet where value like 'facet-bulk-%' or value like 'Facet Bulk%' or value like '%Last' or value like '%-last' order by value$$,
  $$values ('Facet Bulk Map'), ('Facet Map Last'), ('facet-bulk-0'), ('facet-bulk-1'), ('facet-bulk-2'), ('facet-bulk-3'), ('facet-bulk-4'), ('facet-game-last')$$,
  'over 1500 more items still give each game and map once, including the last row');

select * from finish();
rollback;
