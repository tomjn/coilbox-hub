-- The shape of the game catalog (issue #223). Five tables, and the rules that
-- keep them honest are constraints rather than code.
--
-- A second row under one canonical shortname would split a game's record in
-- two with nothing to say which half to read, two rows for one release would
-- make "as of this version" ambiguous, and a links blob that is not an array
-- renders as one broken link on every page that reads it. So they are asserted
-- here rather than trusted to the route that writes them.
--
-- Grants and policies are not tested here. game_access.test.sql covers the
-- behaviour and table_privileges.test.sql asserts the grants directly.

begin;
select plan(26);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('66666666-6666-6666-6666-666666666666', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

-- The happy path first, so a later failure can be told apart from the table
-- refusing everything.
select lives_ok(
  $$insert into public.game (id, shortname, display_name, description, submitted_by)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'BA', 'Balanced Annihilation', 'The classic total annihilation balance mod.', '66666666-6666-6666-6666-666666666666')$$,
  'a game is one row under one canonical shortname'
);

-- Identity. The shortname is the key, so a second row under it splits one
-- game's facts in two with nothing to say which half to serve.
select throws_ok(
  $$insert into public.game (shortname) values ('BA')$$,
  '23505',
  null,
  'a second row under the same shortname is refused'
);

select throws_ok(
  $$insert into public.game (shortname) values ('   ')$$,
  '23514',
  null,
  'a blank shortname is refused rather than stored as an identity'
);

-- Links are an array of labelled rows. An object would render as one broken
-- link instead of failing at the boundary that should have caught it.
select throws_ok(
  $$insert into public.game (shortname, links) values ('XTA', '{"label": "forum"}')$$,
  '23514',
  null,
  'links that are not an array are refused'
);

select is(
  (select links from public.game where shortname = 'BA'),
  '[]'::jsonb,
  'a game starts with no links rather than a placeholder pretending to be one'
);

-- Versions, one row per release anybody reported facts for.
select lives_ok(
  $$insert into public.game_version (game_id, version)
    values ('0f8fad5b-0003-4000-8000-000000000001', '1.9.0')$$,
  'a reported release gets a row'
);

select throws_ok(
  $$insert into public.game_version (game_id, version)
    values ('0f8fad5b-0003-4000-8000-000000000001', '1.9.0')$$,
  '23505',
  null,
  'one release is one row, so as-of queries have one answer'
);

-- Factions, keyed independently of how the name is spelled.
select lives_ok(
  $$insert into public.game_faction (game_id, key, name)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armada', 'Armada')$$,
  'a faction is one row per side per game'
);

select throws_ok(
  $$insert into public.game_faction (game_id, key, name)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armada', 'ARMADA')$$,
  '23505',
  null,
  'a second row under the same faction key is refused'
);

select throws_ok(
  $$insert into public.game_faction (game_id, key, name)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'cortex', '   ')$$,
  '23514',
  null,
  'a faction nobody can read is refused'
);

-- Units, keyed the way public.asset keys their pictures.
select lives_ok(
  $$insert into public.game_unit (game_id, unit_name, full_name, faction_key, build_options, facts_digest, source_version)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armcom', 'Commander', 'armada', '{}', 'digest-armcom', '1.9.0')$$,
  'a unit is one row per name per game'
);

select throws_ok(
  $$insert into public.game_unit (game_id, unit_name, facts_digest)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armcom', 'digest-again')$$,
  '23505',
  null,
  'a second row under the same unit name is refused'
);

select is(
  (select stats from public.game_unit where unit_name = 'armcom'),
  '{}'::jsonb,
  'a unit without measurements holds no measurements rather than zeros claiming to be them'
);

select is(
  (select build_options from public.game_unit where unit_name = 'armcom'),
  '{}',
  'and no build edges rather than an edge list pretending to be empty on purpose'
);

-- The digest is what makes "unchanged since last time" one equality, so a row
-- without one would read as changed forever.
select throws_ok(
  $$insert into public.game_unit (game_id, unit_name)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armsolar')$$,
  '23502',
  null,
  'a unit without a facts digest is refused'
);

-- Revisions, one per version per unit.
select lives_ok(
  $$insert into public.game_unit_revision (unit_id, version, stats, facts_digest)
    values ((select id from public.game_unit where unit_name = 'armcom'), '1.9.0', '{"health": 5000}', 'digest-armcom')$$,
  'a revision records what this release said about the unit'
);

select throws_ok(
  $$insert into public.game_unit_revision (unit_id, version, stats, facts_digest)
    values ((select id from public.game_unit where unit_name = 'armcom'), '1.9.0', '{"health": 9999}', 'digest-other')$$,
  '23505',
  null,
  'a second revision for the same version is refused, because re-extraction replaces rather than appends'
);

select lives_ok(
  $$insert into public.game_unit_revision (unit_id, version, stats, facts_digest)
    values ((select id from public.game_unit where unit_name = 'armcom'), '1.8.0', '{"health": 4500}', 'digest-old')$$,
  'the same facts under a new release are still a new revision'
);

-- Retirement marks rather than removes.
select lives_ok(
  $$insert into public.game_unit (game_id, unit_name, facts_digest)
    values ('0f8fad5b-0003-4000-8000-000000000001', 'armfark', 'digest-fark')$$,
  'a second unit arrives'
);

select lives_ok(
  $$update public.game_unit set removed_at = now() where unit_name = 'armfark'$$,
  'and a submission that stopped listing it retires it'
);

select is(
  (select count(*) from public.game_unit where removed_at is not null)::int, 1,
  'retirement is recorded'
);

select is(
  (select count(*) from public.game_unit where unit_name = 'armfark')::int, 1,
  'and a retired unit is still there, because an old replay still names it'
);

-- Everything inside the catalog hangs off its game.
delete from public.game where shortname = 'BA';

select is(
  (select count(*) from public.game_version)::int, 0,
  'deleting a game takes its versions with it'
);

select is(
  (select count(*) from public.game_faction)::int, 0,
  'and its factions'
);

select is(
  (select count(*) from public.game_unit)::int, 0,
  'and its units'
);

select is(
  (select count(*) from public.game_unit_revision)::int, 0,
  'and every revision those units carried'
);

select * from finish();
rollback;
