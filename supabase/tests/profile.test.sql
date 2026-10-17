-- The display name the hub owns (issue #429).
--
-- Every account gets exactly one public.profile row the moment it exists, and
-- the name in it is what a published item and the moderator directory read. The
-- seed has to produce a name for any account at all, whichever provider made it,
-- so the cases below cover a Discord shaped account, accounts with less, and an
-- account with nothing.

begin;
select plan(29);

create extension if not exists pgtap with schema extensions;

-- Accounts as each provider leaves them. The names are odd on purpose, so a
-- leftover account on a developer's machine cannot collide with them.
insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('a0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'ada@example.test', '{"full_name":"  Zyx Ada  ","name":"zyx_ada"}'),
  ('a0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'b@example.test', '{"name":"Zyx Name"}'),
  ('a0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'c@example.test', '{"preferred_username":"zyx_preferred"}'),
  ('a0000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'd@example.test', '{"user_name":"zyx_user"}'),
  ('a0000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.email.only@example.test', '{}'),
  ('a0000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', null, null),
  ('a0000000-0000-0000-0000-000000000007', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.short@example.test', '{"full_name":"Z","name":"Zyx Second Key"}'),
  ('a0000000-0000-0000-0000-000000000008', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.blank@example.test', '{"full_name":"   "}'),
  ('a0000000-0000-0000-0000-000000000009', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'x@example.test', '{"full_name":"Z"}');

-- Seeding, one rule at a time.
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000001'),
  'Zyx Ada',
  'full_name comes first, trimmed'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000002'),
  'Zyx Name',
  'name is next'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000003'),
  'zyx_preferred',
  'then preferred_username'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000004'),
  'zyx_user',
  'then user_name'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000005'),
  'zyx.email.only',
  'an account with no provider name gets the local part of its email address'
);
select ok(
  (select length(display_name) between 2 and 40 from public.profile where user_id = 'a0000000-0000-0000-0000-000000000006'),
  'an account with no metadata and no email still gets a usable name'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000007'),
  'Zyx Second Key',
  'a provider name too short for the check gives way to the next key'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000008'),
  'zyx.blank',
  'a blank provider name is no name at all'
);
select ok(
  (select display_name <> 'x' and length(display_name) between 2 and 40
     from public.profile where user_id = 'a0000000-0000-0000-0000-000000000009'),
  'a local part too short for the check falls through to a generated name'
);

select is(
  (select count(*) from public.profile where user_id::text like 'a0000000-0000-0000-0000-00000000000_')::int,
  9,
  'every account has exactly one profile'
);

-- Two people whose provider names collide, the second differing only in case.
-- Neither sign up may fail because the other got there first.
insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('a0000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'e@example.test', '{"full_name":"zyx ada"}'),
  ('a0000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'f@example.test', '{"full_name":"Zyx Ada"}');

select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000010'),
  'zyx ada 2',
  'a name somebody already holds, in any case, is disambiguated rather than refused'
);
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000011'),
  'Zyx Ada 3',
  'and the next one along gets the next number'
);

-- A name at the length limit still fits once it carries a number.
insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('a0000000-0000-0000-0000-000000000012', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'g@example.test', '{"full_name":"Zyx 456789012345678901234567890123456789"}'),
  ('a0000000-0000-0000-0000-000000000013', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'h@example.test', '{"full_name":"Zyx 456789012345678901234567890123456789"}');

select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000013'),
  'Zyx 4567890123456789012345678901234567 2',
  'a disambiguated name is shortened to stay inside the length check'
);

-- The backfill runs the same seed over accounts that predate the table. An
-- account whose profile has gone is given one again by the same rules.
delete from public.profile where user_id = 'a0000000-0000-0000-0000-000000000002';
select is(
  public.seed_profile('a0000000-0000-0000-0000-000000000002'),
  'Zyx Name',
  'the backfill seed gives an account without a profile the same name sign up would'
);
select is(
  public.seed_profile('a0000000-0000-0000-0000-000000000002'),
  'Zyx Name',
  'seeding an account that already has a profile leaves it alone'
);

-- The seed is the migration's and the trigger's, not a browser's.
select function_privs_are('public', 'seed_profile', ARRAY['uuid'], 'anon',
  ARRAY[]::name[], 'anon cannot seed a profile');
select function_privs_are('public', 'seed_profile', ARRAY['uuid'], 'authenticated',
  ARRAY[]::name[], 'authenticated cannot seed a profile');

-- Closing an account takes the profile with it.
delete from auth.users where id = 'a0000000-0000-0000-0000-000000000004';
select is(
  (select count(*) from public.profile where user_id = 'a0000000-0000-0000-0000-000000000004')::int,
  0,
  'deleting an account deletes its profile'
);

-- Anybody may read a name, because one already appears next to every
-- published item.
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000001'),
  'Zyx Ada',
  'anon can read a display name'
);

-- Signed in as Ada.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"a0000000-0000-0000-0000-000000000001","role":"authenticated"}';

select lives_ok(
  $$update public.profile set display_name = 'Zyx Countess' where user_id = 'a0000000-0000-0000-0000-000000000001'$$,
  'an account can rename itself'
);

update public.profile set display_name = 'Zyx Defaced' where user_id = 'a0000000-0000-0000-0000-000000000002';
select is(
  (select display_name from public.profile where user_id = 'a0000000-0000-0000-0000-000000000002'),
  'Zyx Name',
  'an account cannot rename somebody else'
);

select throws_ok(
  $$update public.profile set display_name = 'zyx NAME' where user_id = 'a0000000-0000-0000-0000-000000000001'$$,
  '23505',
  null,
  'a name somebody else holds, in any case, is refused'
);

select throws_ok(
  $$update public.profile set display_name = ' Z ' where user_id = 'a0000000-0000-0000-0000-000000000001'$$,
  '23514',
  null,
  'a name shorter than the check allows is refused'
);

select throws_ok(
  $$update public.profile set user_id = 'a0000000-0000-0000-0000-000000000003' where user_id = 'a0000000-0000-0000-0000-000000000001'$$,
  '42501',
  null,
  'a profile cannot be handed to another account'
);

select throws_ok(
  $$insert into public.profile (user_id, display_name) values ('a0000000-0000-0000-0000-000000000001', 'Zyx Twice')$$,
  '42501',
  null,
  'an account cannot insert a profile, the seed already made one'
);

-- The trust default reads the profile, so a new item carries the renamed name
-- and nothing a client sends can change it.
select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('preset', 1, 'Zyx renamed item', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', 'a0000000-0000-0000-0000-000000000001')$$,
  'a renamed account can publish'
);
select is(
  (select author_name from public.item where title = 'Zyx renamed item'),
  'Zyx Countess',
  'item.author_name comes from the profile, not the provider metadata'
);

-- The moderator directory reads the profile too.
reset role;
insert into public.user_capability (user_id, capability)
values ('a0000000-0000-0000-0000-000000000001', 'can_moderate');
set local role authenticated;
set local request.jwt.claims = '{"sub":"a0000000-0000-0000-0000-000000000001","role":"authenticated"}';

select is(
  (select display_name from public.moderator_accounts('a0000000-0000-0000-0000-000000000001', 0)),
  'Zyx Countess',
  'the moderator directory shows the profile name'
);
select is(
  (select count(*) from public.moderator_accounts('Zyx Countess', 0))::int,
  1,
  'the moderator directory searches the profile name'
);

select * from finish();
rollback;
