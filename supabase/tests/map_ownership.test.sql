-- Who may ask to own a map or an author, and who decides, run as the roles
-- PostgREST uses.

begin;
select plan(10);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'mapper@example.test', '{"full_name": "Map Maker"}'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'other@example.test', '{}'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test', '{}');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.map (
  id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  appearance, curated_tags, source_hash, source_archive, catalog_version, facts_digest
)
values ('22222222-0000-4000-8000-000000000001', 'Asked 1.0', 'asked-1-0', 4096, 4096, 0, 100,
  '{}'::jsonb, '{}', 'src-asked', 'asked_1.0.sd7', 1, 'digest-asked');

-- The mapper asks.
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$insert into public.map_ownership_request (map_id, requested_by, note)
    values ('22222222-0000-4000-8000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'I made this map')$$,
  'a signed in account asks to own a map'
);

select lives_ok(
  $$insert into public.map_ownership_request (author_key, requested_by)
    values ('mapmaker', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')$$,
  'and, separately, to own an author'
);

select is(
  (select requested_by_name from public.map_ownership_request where map_id is not null),
  'Map Maker',
  'the name on a request comes from the profile'
);

select throws_ok(
  $$insert into public.map_ownership_request (map_id, requested_by)
    values ('22222222-0000-4000-8000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')$$,
  '23505', null,
  'a second open ask for the same map is refused'
);

select throws_ok(
  $$insert into public.map_ownership_request (author_key, requested_by)
    values ('mapmaker', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')$$,
  '23505', null,
  'a second open ask for the same author is refused'
);

select throws_ok(
  $$insert into public.map_ownership_request (map_id, author_key, requested_by)
    values ('22222222-0000-4000-8000-000000000001', 'mapmaker', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')$$,
  '23514', null,
  'a request names a map or an author, not both'
);

select throws_ok(
  $$insert into public.map_ownership_request (map_id, requested_by)
    values ('22222222-0000-4000-8000-000000000001', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')$$,
  '42501', null,
  'nobody asks as somebody else'
);

-- Another account sees nothing and decides nothing.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select is((select count(*)::int from public.map_ownership_request), 0, 'who wants a map is not a listing');

update public.map_ownership_request set state = 'approved';
reset role;
select is(
  (select count(*)::int from public.map_ownership_request where state = 'open'),
  2,
  'a stranger cannot decide a request'
);

-- A moderator sees the queue.
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select is((select count(*)::int from public.map_ownership_request), 2, 'a moderator reads the queue');

select * from finish();
rollback;
