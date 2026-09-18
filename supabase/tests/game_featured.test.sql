-- Who may put a game at the top of the listing, run as the roles PostgREST
-- actually uses.
--
-- Featuring is the first game column that an owner may read and only a
-- moderator may write. Hiding is close but not the same: an owner may hide
-- their own game, because hiding is a decision about their own page, while
-- featuring is a decision about the hub's front door. This proves the
-- difference holds at the data layer rather than in whichever page remembered
-- to check.
--
-- Where the game is downloaded from used to be the other half of this file.
-- It became public.game_download_source in #396, and its own test with it.

begin;
select plan(5);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0007-4000-8000-000000000042', 'BA', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

-- service_role features it, which is what the server action does once it has
-- checked who is asking.
reset role;
set local role service_role;

update public.game
set featured_at = now(), featured_by = 'cccccccc-cccc-cccc-cccc-cccccccccccc'
where shortname = 'BA';

select is(
  (select featured_at is not null from public.game where shortname = 'BA'),
  true,
  'service_role can feature a game'
);

-- anon reads it, because the listing has to draw the featured block for a
-- signed out visitor.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select featured_at is not null from public.game_browse where shortname = 'BA'),
  true,
  'anon reads featured_at through the browse view'
);

select is(
  (select count(*)::integer from public.game_browse where shortname = 'BA'),
  1,
  'featuring does not change what anon can see'
);

-- The owner may not write it. The column grant is what refuses this, before
-- any policy is consulted.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select throws_ok(
  $$update public.game set featured_at = null where shortname = 'BA'$$,
  '42501',
  null,
  'the owner may not unfeature their own game'
);

-- The owner may still write the columns they have always written.
select lives_ok(
  $$update public.game set display_name = 'Balanced Annihilation' where shortname = 'BA'$$,
  'the owner may set the display name'
);

select * from finish();
rollback;
