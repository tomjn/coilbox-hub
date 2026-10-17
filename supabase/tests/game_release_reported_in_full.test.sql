-- Whether a release was reported in full (#493), as submit_game_facts records
-- it on game_version.reported_in_full.
--
-- The release changes page lists a unit as removed only when the newer release
-- is known to be complete, so the column must be true only after a complete
-- submission that stored every unit it named, and must never fall back to false
-- once set.

begin;
select plan(9);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('88888888-8888-8888-8888-888888888888', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

reset role;
set local role service_role;

select is(
  (select is_nullable from information_schema.columns
    where table_schema = 'public' and table_name = 'game_version' and column_name = 'reported_in_full'),
  'NO',
  'a release is always either known to be complete or not, never null'
);

-- A submission that does not say it is complete, as every current build sends.
select public.submit_game_facts($j$
  {"shortname": "BA", "release": "1.0.0",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '1.0.0'), false, 'a release reported without a completeness claim is not known to be complete');

select public.submit_game_facts($j$
  {"shortname": "BA", "release": "1.0.0", "complete": false,
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '1.0.0'), false, 'a submission that says it is partial leaves the release not known to be complete');

select public.submit_game_facts($j$
  {"shortname": "BA", "release": "2.0.0", "complete": true,
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"},
             {"unit": {"name": "armmex"}, "facts_digest": "d2"}]}
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '2.0.0'), true, 'a complete submission marks a new release as reported in full');

select public.submit_game_facts($j$
  {"shortname": "BA", "release": "2.0.0",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '2.0.0'), true, 'a later partial report does not take the mark off a complete release');

select public.submit_game_facts($j$
  {"shortname": "BA", "release": "1.0.0", "complete": true,
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '1.0.0'), true, 'a complete report of a release first seen as partial marks it complete');

-- A complete declaration in which one unit was refused did not store every unit.
create temp table with_refusal as
select public.submit_game_facts(
  jsonb_build_object(
    'shortname', 'BA',
    'release', '3.0.0',
    'complete', true,
    'units', jsonb_build_array(
      jsonb_build_object('unit', jsonb_build_object('name', 'armcom'), 'facts_digest', 'd1'),
      jsonb_build_object('unit', jsonb_build_object('name', repeat('x', 200)), 'facts_digest', 'd-bad')
    )
  ),
  '88888888-8888-8888-8888-888888888888'
) as outcomes;

select is(
  with_refusal.outcomes -> 1 ->> 'outcome', 'refused',
  'the oversized unit name is refused, as the premise of the next check'
) from with_refusal;

select is((select gv.reported_in_full from public.game_version gv join public.game g on g.id = gv.game_id where g.shortname = 'BA' and gv.version = '3.0.0'), false, 'a complete report that had a unit refused is not known to be complete');

select is(
  (select count(*) from public.game_version where reported_in_full)::int, 2,
  'only the releases that were reported in full carry the mark'
);

select * from finish();
rollback;
