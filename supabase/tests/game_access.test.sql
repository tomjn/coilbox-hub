-- Who may read and write the game catalog (issue #223), run as the roles
-- PostgREST actually uses. `anon` is a visitor with no session, `authenticated`
-- is somebody signed in with Discord, and both hold the publishable key.
-- `service_role` is the Vercel route and nothing else.
--
-- Like the map catalog, this one hides nothing: a row describes a game that is
-- already published everywhere else. What the layers are for is the writing,
-- because facts_digest, source_version and the faction keys are computed by
-- the route, and a row written straight through PostgREST would read as
-- unchanged facts forever and belong to nobody.
--
-- A grant and a policy are two layers and either one shut is enough, which is
-- why table_privileges.test.sql asserts the grants directly as well.

begin;
select plan(19);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('77777777-7777-7777-7777-777777777777', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

-- One game with everything hanging off it, so each table has a row to be read
-- or refused. Every count names its own rows rather than counting the table,
-- so leftover rows fail on the access rules rather than hiding them.
insert into public.game (id, shortname, display_name, submitted_by)
values ('0f8fad5b-0004-4000-8000-000000000001', 'BA', 'Balanced Annihilation', '77777777-7777-7777-7777-777777777777');

insert into public.game_version (game_id, version)
values ('0f8fad5b-0004-4000-8000-000000000001', '1.9.0');

insert into public.game_faction (game_id, key, name)
values ('0f8fad5b-0004-4000-8000-000000000001', 'armada', 'Armada');

insert into public.game_unit (game_id, unit_name, faction_key, facts_digest)
values ('0f8fad5b-0004-4000-8000-000000000001', 'armcom', 'armada', 'digest-armcom');

insert into public.game_unit_revision (unit_id, version, facts_digest)
values ((select id from public.game_unit where unit_name = 'armcom'), '1.9.0', 'digest-armcom');

-- A visitor with no account at all.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'a visitor reads a game'
);

select is(
  (select count(*) from public.game_version where version = '1.9.0')::int, 1,
  'and every release reported for it'
);

select is(
  (select count(*) from public.game_faction where key = 'armada')::int, 1,
  'and who its sides are'
);

select is(
  (select count(*) from public.game_unit where unit_name = 'armcom')::int, 1,
  'and every unit in it'
);

select is(
  (select count(*) from public.game_unit_revision)::int, 1,
  'and what older releases said about those units'
);

select throws_ok(
  $$insert into public.game (shortname) values ('XX')$$,
  '42501',
  null,
  'a visitor cannot write itself a game, since the route is what computes the digest'
);

select throws_ok(
  $$update public.game set display_name = 'Something Else'$$,
  '42501',
  null,
  'nor rename one'
);

select throws_ok(
  $$delete from public.game$$,
  '42501',
  null,
  'nor remove one'
);

-- Signed in, and the account that submitted the facts.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"77777777-7777-7777-7777-777777777777","role":"authenticated"}';

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'an account reads exactly what a visitor reads, because there is nothing here to hide'
);

select throws_ok(
  $$update public.game_unit set stats = '{"health": 9999}'$$,
  '42501',
  null,
  'a signed in account cannot correct the facts directly, because a correction is a resubmission through the route'
);

select throws_ok(
  $$insert into public.game_faction (game_id, key, name)
    values ('0f8fad5b-0004-4000-8000-000000000001', 'cortex', 'Cortex')$$,
  '42501',
  null,
  'nor decide for itself who the sides are'
);

select throws_ok(
  $$delete from public.game_unit_revision$$,
  '42501',
  null,
  'nor erase what an older release said'
);

-- The Vercel route, which holds the secret key and nothing in a browser does.
reset role;
set local role service_role;

select lives_ok(
  $$insert into public.game (id, shortname) values ('0f8fad5b-0004-4000-8000-000000000002', 'XTA')$$,
  'the route writes a game, which is the only way one is ever written'
);

select lives_ok(
  $$update public.game_unit set last_seen_at = now()$$,
  'and records that a client reported these units again'
);

-- A faction list is a replaced set: a resubmission rewrites it, so a side a
-- balance patch removed has to lose its row.
select lives_ok(
  $$delete from public.game_faction where key = 'armada'$$,
  'and replaces a whole set of factions'
);

-- Everything else outlives a submission, so nothing may remove it: a retired
-- unit is still named by old replays, and a version or revision is a record of
-- something that existed.
select throws_ok(
  $$delete from public.game_unit$$,
  '42501',
  null,
  'units retire rather than disappear'
);

select throws_ok(
  $$delete from public.game_unit_revision$$,
  '42501',
  null,
  'revisions stay'
);

select throws_ok(
  $$delete from public.game_version$$,
  '42501',
  null,
  'versions stay'
);

select throws_ok(
  $$delete from public.game where shortname = 'XTA'$$,
  '42501',
  null,
  'and so does the game itself'
);

select * from finish();
rollback;
