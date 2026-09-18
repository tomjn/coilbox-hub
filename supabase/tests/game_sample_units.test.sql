-- What public.game_sample_units picks, and how much of it.
--
-- The listing card draws this for a game that has never reported its start
-- units, so the decisions worth pinning are the ones a card would show wrong:
-- that each game gets its own units and not another game's, that a retired unit
-- never appears, and that the count is clamped however much a caller asks for.
--
-- Which three come back is random by design, so nothing here asserts a
-- particular unit. Every assertion is over a set the randomness cannot change:
-- ask for as many as the game has, and the answer is all of them.

begin;
select plan(9);

create extension if not exists pgtap with schema extensions;

insert into public.game (id, shortname)
values
  ('0f8fad5b-0007-4000-8000-000000000001', 'BA'),
  ('0f8fad5b-0007-4000-8000-000000000002', 'XTA'),
  ('0f8fad5b-0007-4000-8000-000000000003', 'EMPTY');

insert into public.game_unit (game_id, unit_name, full_name, facts_digest)
values
  ('0f8fad5b-0007-4000-8000-000000000001', 'armcom', 'Armada Commander', 'd1'),
  ('0f8fad5b-0007-4000-8000-000000000001', 'armmex', null, 'd2'),
  ('0f8fad5b-0007-4000-8000-000000000001', 'armfark', null, 'd3'),
  ('0f8fad5b-0007-4000-8000-000000000001', 'armretired', null, 'd4');

-- Eight for XTA, which is more than the clamp, so the clamp has something to
-- cut.
insert into public.game_unit (game_id, unit_name, facts_digest)
select '0f8fad5b-0007-4000-8000-000000000002', 'xta' || n, 'x' || n
from generate_series(1, 8) as n;

update public.game_unit set removed_at = now() where unit_name = 'armretired';

select results_eq(
  $$select unit_name from public.game_sample_units(ARRAY['BA'], 3) order by unit_name$$,
  ARRAY['armcom', 'armfark', 'armmex'],
  'asking for as many units as a game has live returns all of them'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['BA'], 99)
    where unit_name = 'armretired')::int, 0,
  'a retired unit is never picked, however many are asked for'
);

select is(
  (select full_name from public.game_sample_units(ARRAY['BA'], 99)
    where unit_name = 'armcom'), 'Armada Commander',
  'the name a reader sees comes back with the unit, null and all'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['XTA'], 99))::int, 6,
  'the count is clamped, so a caller cannot pull a whole unit list one call at a time'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['XTA'], 3))::int, 3,
  'and is honoured below the clamp'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['BA', 'XTA'], 3)
    where shortname = 'BA' and unit_name like 'xta%')::int, 0,
  'one call covers several games and no game is handed another game''s units'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['EMPTY'], 3))::int, 0,
  'a game with no units yields nothing rather than a row of nulls'
);

select is(
  (select count(*) from public.game_sample_units(ARRAY['nosuchgame'], 3))::int, 0,
  'and a shortname nobody holds yields nothing'
);

-- Asked in a browser, by the games listing and by a game's own page. The
-- routes have no use for it, so service_role is not on the grant.
select function_privs_are('public', 'game_sample_units', ARRAY['text[]', 'integer'],
  'anon', ARRAY['EXECUTE'],
  'a visitor may ask for a sample, which is the whole of who this is for');

select * from finish();
rollback;
