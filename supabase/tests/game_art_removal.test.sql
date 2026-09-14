-- Removing a game's logo or banner (issue #360).
--
-- Removal clears the three columns for one picture, with the secret key, after
-- `editableGame` has found the game editable (the columns are not in the signed
-- in grant, which game_ownership.test.sql proves). What only the database can
-- prove is that the staged copy it leaves in the bucket is then something the
-- daily sweep lists and may delete, and that the removal itself is never held
-- up by a deletion reservation.

begin;
select plan(8);

create extension if not exists pgtap with schema extensions;

insert into public.game (id, shortname, logo_path, logo_hash, logo_staged_tier, banner_path, banner_hash, banner_staged_tier)
values ('0f8fad5b-3600-4000-8000-000000000001', 'XTA', 'games/XTA/logo.webp', 'logo-hash', 'bucket', 'games/XTA/banner.webp', 'banner-hash', 'bucket');

insert into storage.objects (bucket_id, name, metadata)
values
  ('staged-pictures', 'games/XTA/logo.webp', '{"size": 1024}'),
  ('staged-pictures', 'games/XTA/banner.webp', '{"size": 1024}');

reset role;
set local role service_role;
set local request.jwt.claims = '';

select ok(
  not exists (select 1 from public.unclaimed_staged_objects(100) where object_path like 'games/XTA/%'),
  'while the row names both pictures, the sweep lists neither'
);

select is(
  (select count(*)::int from public.reserve_staged_deletions(ARRAY['games/XTA/logo.webp'], true)),
  0,
  'and cannot reserve the logo for deletion'
);

-- What `removeGameImage` writes.
select lives_ok(
  $$update public.game set logo_path = null, logo_hash = null, logo_staged_tier = null
    where id = '0f8fad5b-3600-4000-8000-000000000001'$$,
  'removing the logo clears its three columns'
);

select set_eq(
  $$select object_path from public.unclaimed_staged_objects(100) where object_path like 'games/XTA/%'$$,
  ARRAY['games/XTA/logo.webp'],
  'the removed logo''s bucket object is now listed by the sweep, and the banner still is not'
);

select set_eq(
  $$select object_path from public.reserve_staged_deletions(ARRAY['games/XTA/logo.webp', 'games/XTA/banner.webp'], true)$$,
  ARRAY['games/XTA/logo.webp'],
  'and the sweep can reserve it for deletion, while the banner stays claimed'
);

select throws_ok(
  $$update public.game set logo_path = 'games/XTA/logo.webp', logo_hash = 'new-hash', logo_staged_tier = 'bucket'
    where id = '0f8fad5b-3600-4000-8000-000000000001'$$,
  '55006', null,
  'a new upload to the same path waits until the sweep has finished deleting it'
);

select lives_ok(
  $$update public.game set banner_path = null, banner_hash = null, banner_staged_tier = null
    where id = '0f8fad5b-3600-4000-8000-000000000001'$$,
  'removing the banner is not held up by the logo''s reservation'
);

select lives_ok(
  $$update public.game set logo_path = null, logo_hash = null, logo_staged_tier = null
    where id = '0f8fad5b-3600-4000-8000-000000000001'$$,
  'nor is removing a picture twice, even with its path reserved'
);

select * from finish();
rollback;
