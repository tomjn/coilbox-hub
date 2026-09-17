-- The moderator account directory (issue #385).
--
-- The table this reads (auth.users) holds email, phone and password material
-- for every account, so the property that matters is a negative one: nobody
-- outside the function reaches any of it, and the function reaches nobody
-- outside the moderator check. The function_privileges suite proves new
-- functions start closed; these prove this one stays closed by design rather
-- than by omission.

begin;
select plan(9);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'maintainer@example.test', '{"full_name":"Ada Maintainer"}'),
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'seeder@example.test', '{"name":"Seed Helper"}'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test', '{}');

insert into public.user_capability (user_id, capability) values
  ('11111111-1111-1111-1111-111111111111', 'can_moderate'),
  ('11111111-1111-1111-1111-111111111111', 'can_seed_unit_assets'),
  ('22222222-2222-2222-2222-222222222222', 'can_seed_unit_assets');

-- A signed-in account with no capability learns nothing, not even that the
-- function exists.
select throws_ok(
  $$set local role authenticated;
    set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';
    select * from public.moderator_accounts(null, 0)$$,
  '42501',
  'moderator_accounts is for moderators',
  'an ordinary account cannot read the directory'
);

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select function_privs_are('public', 'moderator_accounts', ARRAY['text','integer'], 'anon',
  ARRAY[]::name[], 'anon holds no grant at all');

-- The directory itself, as the moderator sees it.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from public.moderator_accounts(null, 0))::int, 3,
  'the directory lists every account, not only ones who published'
);

select is(
  (select display_name from public.moderator_accounts('22222222-2222-2222-2222-222222222222', 0)),
  'Seed Helper',
  'an id finds the account and reads its name the way the header spells it'
);

select is(
  (select display_name from public.moderator_accounts('seed', 0)),
  'Seed Helper',
  'a name finds the account'
);

select is(
  (select display_name from public.moderator_accounts('SEED', 0)),
  'Seed Helper',
  'a name search is case insensitive'
);

select is(
  (select array_agg(capability order by capability)
     from unnest((select capabilities from public.moderator_accounts('11111111-1111-1111-1111-111111111111', 0))) capability),
  '{can_moderate,can_seed_unit_assets}',
  'capabilities come back sorted, both of them'
);

select is(
  (select capabilities from public.moderator_accounts('33333333-3333-3333-3333-333333333333', 0)),
  '{}',
  'an account with no capability says so with an empty list'
);

-- Paging. Three accounts and a page of two means page two holds one.
select is(
  (select count(*) from public.moderator_accounts(null, 2))::int, 1,
  'the offset pages'
);

select * from finish();
rollback;
