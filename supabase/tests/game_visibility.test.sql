-- What hiding means in practice (#242), run as the roles PostgREST actually
-- uses.
--
-- The rules being proved: a hidden game is invisible to a visitor and to any
-- account that is neither its owner nor a moderator, at every level of the
-- catalog at once, including the browse view the listing reads; hiding one
-- release takes that release's revisions with it while everything else stands;
-- and the submission route keeps writing through all of it, because
-- service_role is what visibility never binds.

begin;
select plan(18);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'other@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0007-4000-8000-000000000001', 'BA', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

insert into public.game_version (game_id, version)
values
  ('0f8fad5b-0007-4000-8000-000000000001', '1.9.0'),
  ('0f8fad5b-0007-4000-8000-000000000001', '2.0.0');

insert into public.game_faction (game_id, key, name)
values ('0f8fad5b-0007-4000-8000-000000000001', 'armada', 'Armada');

insert into public.game_unit (game_id, unit_name, facts_digest)
values ('0f8fad5b-0007-4000-8000-000000000001', 'armcom', 'd1');

insert into public.game_unit_revision (unit_id, version, facts_digest)
values (
  (select id from public.game_unit where unit_name = 'armcom'),
  '1.9.0',
  'd1'
);

-- The whole game goes.
reset role;
set local role service_role;
update public.game set hidden_at = now(), hidden_by = 'cccccccc-cccc-cccc-cccc-cccccccccccc' where shortname = 'BA';

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 0,
  'a hidden game is not served to a visitor'
);

select is(
  (select count(*) from public.game_faction)::int, 0,
  'and nothing hanging off it is either'
);

select is(
  (select count(*) from public.game_unit)::int, 0,
  'not its units'
);

select is(
  (select count(*) from public.game_version)::int, 0,
  'nor its releases'
);

select is(
  (select count(*) from public.game_unit_revision)::int, 0,
  'nor their history'
);

select is(
  (select count(*) from public.game_browse)::int, 0,
  'and the listing view loses the row too'
);

-- A stranger sees exactly what a visitor sees.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 0,
  'an unrelated account cannot see it either'
);

-- Its owner can.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'the owner still sees their own hidden game'
);

select is(
  (select count(*) from public.game_unit)::int, 1,
  'with everything under it'
);

-- As does a moderator.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'a moderator sees through the hide'
);

select is(
  (select count(*) from public.game_version)::int, 2,
  'at every level'
);

-- The write path is untouched by visibility, which is what lets a backfill
-- carry on while a page is hidden.
reset role;
set local role service_role;

select lives_ok(
  $$insert into public.game_version as gv (game_id, version)
    values ('0f8fad5b-0007-4000-8000-000000000001', '1.9.0')
    on conflict (game_id, version) do update set last_seen_at = now()$$,
  'the facts route keeps writing while the game is hidden'
);

-- One release goes, and takes only its own history with it.
update public.game set hidden_at = null where shortname = 'BA';
update public.game_version set hidden_at = now() where version = '1.9.0';

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'hiding a release leaves the game on the site'
);

select is(
  (select count(*) from public.game_version where version = '2.0.0')::int, 1,
  'and every other release too'
);

select is(
  (select count(*) from public.game_version where version = '1.9.0')::int, 0,
  'the hidden release leaves the pickers'
);

select is(
  (select count(*) from public.game_unit_revision where version = '1.9.0')::int, 0,
  'taking its revisions with it'
);

-- Re-reporting a hidden release does not unhide it.
reset role;
set local role service_role;
insert into public.game_version as gv (game_id, version)
values ((select id from public.game where shortname = 'BA'), '1.9.0')
on conflict (game_id, version) do update set last_seen_at = now();

select is(
  (select hidden_at is not null from public.game_version where version = '1.9.0'),
  true,
  'a backfill re-reporting a hidden release leaves it hidden'
);

-- And the moderator who hid it still sees both sides of it.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select is(
  (select count(*) from public.game_version)::int, 2,
  'a moderator sees the hidden release for management'
);

select * from finish();
rollback;
