-- What each column of the games listing view says (issue #225), one assertion
-- per column, in the style of map_browse.test.sql.
--
-- The counts are the whole of the view: everything else is passed through from
-- public.game and is proved by game_catalog.test.sql. What is being proved here
-- is that the aggregates count the right rows - factions as named, units as
-- playable - because a count that quietly includes retired units reads a game
-- as bigger than it is on every card that shows it.

begin;
select plan(7);

create extension if not exists pgtap with schema extensions;

insert into public.game (id, shortname, display_name, description)
values ('0f8fad5b-0005-4000-8000-000000000001', 'BA', 'Balanced Annihilation', 'The classic balance mod.');

insert into public.game_faction (game_id, key, name)
values
  ('0f8fad5b-0005-4000-8000-000000000001', 'armada', 'Armada'),
  ('0f8fad5b-0005-4000-8000-000000000001', 'cortex', 'Cortex');

insert into public.game_unit (game_id, unit_name, facts_digest)
values
  ('0f8fad5b-0005-4000-8000-000000000001', 'armcom', 'd1'),
  ('0f8fad5b-0005-4000-8000-000000000001', 'armmex', 'd2'),
  ('0f8fad5b-0005-4000-8000-000000000001', 'armfark', 'd3');

-- A second game with nothing but its shortname, so the empty side of every
-- aggregate has a row to sit on.
insert into public.game (id, shortname)
values ('0f8fad5b-0005-4000-8000-000000000002', 'XTA');

-- Retirement is recorded, not deleted, so the live count has something to
-- exclude.
update public.game_unit set removed_at = now() where unit_name = 'armfark';

select is(
  (select faction_count from public.game_browse where shortname = 'BA')::int, 2,
  'a game counts its sides'
);

select is(
  (select unit_count from public.game_browse where shortname = 'BA')::int, 2,
  'and its playable units, retired ones excluded'
);

select is(
  (select display_name from public.game_browse where shortname = 'BA'), 'Balanced Annihilation',
  'the name a reader sees passes through'
);

select is(
  (select description from public.game_browse where shortname = 'BA'), 'The classic balance mod.',
  'so does what the page says about it'
);

select is(
  (select faction_count from public.game_browse where shortname = 'XTA')::int, 0,
  'a game nobody has reported facts for counts no sides'
);

select is(
  (select unit_count from public.game_browse where shortname = 'XTA')::int, 0,
  'and no units, which is an ordinary state rather than an error'
);

select results_eq(
  'select shortname from public.game_browse order by unit_count desc, shortname',
  ARRAY['BA', 'XTA'],
  'the listing orders games by how much there is to look at'
);

select * from finish();
rollback;
