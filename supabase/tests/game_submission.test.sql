-- The rules of a game facts submission (#224), run against real rows.
--
-- Everything here is what `public.submit_game_facts` decides, which is the part
-- that cannot be proved in TypeScript without a copy of the rules drifting from
-- the ones that actually run. The route's half - parsing, digests, zipping the
-- answers back into request order - is lib/games/submit.test.ts's.
--
-- The four outcomes get the most attention, because they are the contract a
-- backfill reads: accepted means facts changed, recorded means this release had
-- no revision yet, unchanged means nothing moved, refused names what was wrong.
-- Retirement is the other half nobody may get quietly wrong: a partial
-- submission removes nothing, a complete one retires rather than deletes, and a
-- unit that comes back comes all the way back.

begin;
select plan(27);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('88888888-8888-8888-8888-888888888888', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

reset role;
set local role service_role;

create temp table first_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "1.9.0",
    "startUnits": ["armcom"],
    "factions": [{"key": "armada", "name": "Armada"}],
    "units": [
      {"unit": {"name": "armcom", "full_name": "Commander", "faction_key": "armada", "build_options": ["armsolar"], "stats": {"health": 5000}}, "facts_digest": "d-armcom"},
      {"unit": {"name": "armmex"}, "facts_digest": "d-armmex"}
    ]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  first_report.outcomes -> 0 ->> 'outcome', 'accepted',
  'the first report of a unit stores it'
) from first_report;

select is(
  first_report.outcomes -> 1 ->> 'outcome', 'accepted',
  'and every unit in the batch is answered'
) from first_report;

select is(
  (select count(*) from public.game where shortname = 'BA')::int, 1,
  'a shortname nobody has reported creates its game'
);

select is(
  (select start_units from public.game where shortname = 'BA'),
  ARRAY['armcom'],
  'the roots a build tree groups by ride the first report'
);

select is(
  (select count(*) from public.game_version)::int, 1,
  'the release is recorded once'
);

select is(
  (select gf.name from public.game_faction gf join public.game g on g.id = gf.game_id
    where g.shortname = 'BA' and gf.key = 'armada'), 'Armada',
  'the faction arrives with the units that point at it'
);

select is(
  (select u.full_name from public.game_unit u join public.game g on g.id = u.game_id
    where g.shortname = 'BA' and u.unit_name = 'armcom'), 'Commander',
  'the unit arrives with what the def calls it'
);

select is(
  (select u.source_version from public.game_unit u join public.game g on g.id = u.game_id
    where g.shortname = 'BA' and u.unit_name = 'armcom'), '1.9.0',
  'and the current facts say which release they were read from'
);

select is(
  (select count(*) from public.game_unit_revision)::int, 2,
  'and both units carry a revision for the release they were read at'
);

-- The ordinary case the second time round: nothing changed, nothing written.
create temp table repeat_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "1.9.0",
    "units": [{"unit": {"name": "armcom", "full_name": "Commander", "faction_key": "armada", "build_options": ["armsolar"], "stats": {"health": 5000}}, "facts_digest": "d-armcom"}]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  repeat_report.outcomes -> 0 ->> 'outcome', 'unchanged',
  'identical facts under the same release change nothing'
) from repeat_report;

select is(
  (select count(*) from public.game_unit_revision)::int, 2,
  'and no phantom revision appears'
) from repeat_report;

-- The same facts arriving for a release the hub has never seen are new history,
-- even though they are old news about the unit. The revision is taken away by
-- hand here, because no route may erase one: this runs as the table owner,
-- which is exactly who holds no delete through the API.
reset role;
delete from public.game_unit_revision where version = '1.9.0' and unit_id = (select u.id from public.game_unit u join public.game g on g.id = u.game_id where g.shortname = 'BA' and u.unit_name = 'armcom');
set local role service_role;

create temp table relearned_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "1.9.0",
    "units": [{"unit": {"name": "armcom", "full_name": "Commander", "faction_key": "armada", "build_options": ["armsolar"], "stats": {"health": 5000}}, "facts_digest": "d-armcom"}]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  relearned_report.outcomes -> 0 ->> 'outcome', 'recorded',
  'facts the hub holds but this release lost are worth recording again'
) from relearned_report;

select is(
  (select count(*) from public.game_unit_revision where version = '1.9.0'
     and unit_id = (select u.id from public.game_unit u join public.game g on g.id = u.game_id where g.shortname = 'BA' and u.unit_name = 'armcom'))::int, 1,
  'and exactly one revision per unit per version stands'
) from relearned_report;

-- A balance patch at the same release string is unusual, but a corrected
-- extraction is not: either way the newest reading wins and replaces the
-- revision rather than sitting beside it.
create temp table patched_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "1.9.0",
    "units": [{"unit": {"name": "armcom", "full_name": "Commander", "faction_key": "armada", "build_options": ["armsolar"], "stats": {"health": 4500}}, "facts_digest": "d-armcom-patch"}]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  patched_report.outcomes -> 0 ->> 'outcome', 'accepted',
  'changed facts are taken'
) from patched_report;

select is(
  (select stats from public.game_unit_revision where version = '1.9.0'
     and unit_id = (select u.id from public.game_unit u join public.game g on g.id = u.game_id where g.shortname = 'BA' and u.unit_name = 'armcom')),
  '{"health": 4500}'::jsonb,
  'and the revision for this version is what this version now says, not what it said twice'
);

-- A new release reporting the same facts writes history without moving the
-- present: source_version still names where the current facts were read.
create temp table next_release_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "2.0.0",
    "complete": true,
    "units": [
      {"unit": {"name": "armcom", "full_name": "Commander", "faction_key": "armada", "build_options": ["armsolar"], "stats": {"health": 4500}}, "facts_digest": "d-armcom-patch"},
      {"unit": {"name": "armmex"}, "facts_digest": "d-armmex"}
    ]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select count(*) from public.game_unit_revision)::int, 4,
  'every unit gets a revision for the new release, whatever its outcome'
);

select is(
  (select count(*) from public.game_version)::int, 2,
  'and the release joins the list a picker offers'
);

select is(
  (select u.source_version from public.game_unit u join public.game g on g.id = u.game_id
    where g.shortname = 'BA' and u.unit_name = 'armcom'), '1.9.0',
  'current facts still say where they came from, even when a newer release confirmed them'
);

-- Retirement. armmex was named by the complete report above, so it stands;
-- a unit the batch does not name is the interesting one.
insert into public.game_unit (game_id, unit_name, facts_digest)
values ((select id from public.game where shortname = 'BA'), 'armfark', 'd-fark');

-- First prove the negative: an incomplete naming only armcom must leave
-- armfark alone, because a partial backfill knows nothing about completeness.
create temp table partial_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "2.0.0",
    "units": [{"unit": {"name": "armcom"}, "facts_digest": "d-armcom-patch"}]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select removed_at is null from public.game_unit where unit_name = 'armfark'), true,
  'a partial submission retires nothing, because absence from it means nothing'
);

create temp table complete_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "2.0.0",
    "complete": true,
    "units": [{"unit": {"name": "armcom"}, "facts_digest": "d-armcom-patch"}]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select removed_at is not null from public.game_unit where unit_name = 'armfark'), true,
  'a complete submission retires the unit it did not name'
);

select is(
  (select count(*) from public.game_unit where unit_name = 'armfark')::int, 1,
  'and retiring marks rather than deletes, because an old replay still names it'
);

create temp table comeback_report as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "2.0.0",
    "complete": true,
    "units": [
      {"unit": {"name": "armcom"}, "facts_digest": "d-armcom-patch"},
      {"unit": {"name": "armfark"}, "facts_digest": "d-fark"}
    ]
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select removed_at is null from public.game_unit where unit_name = 'armfark'), true,
  'a retired unit a later release lists again comes all the way back'
) from comeback_report;

-- The faction set is replaced wholesale when present, and untouched when not.
create temp table faction_swap as
select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "2.0.0",
    "factions": [{"key": "cortex", "name": "Cortex"}],
    "units": []
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select array_agg(key order by key) from public.game_faction), ARRAY['cortex'],
  'a resubmission rewrites the faction list rather than appending to it'
);

create temp table faction_kept as
select public.submit_game_facts($j$
  { "shortname": "BA", "release": "2.0.0", "units": [] }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

select is(
  (select array_agg(key order by key) from public.game_faction), ARRAY['cortex'],
  'and a submission without factions leaves the held set alone'
);

-- One bad entry costs itself and not its neighbours.
create temp table mixed_batch as
select public.submit_game_facts(
  jsonb_build_object(
    'shortname', 'BA',
    'release', '2.0.0',
    'units', jsonb_build_array(
      jsonb_build_object(
        'unit', jsonb_build_object('name', 'armcom'),
        'facts_digest', 'd-armcom-patch'
      ),
      jsonb_build_object(
        'unit', jsonb_build_object('name', repeat('x', 200)),
        'facts_digest', 'd-bad'
      )
    )
  ),
  '88888888-8888-8888-8888-888888888888'
) as outcomes;

select is(
  mixed_batch.outcomes -> 1 ->> 'outcome', 'refused',
  'an entry the tables cannot hold is refused'
) from mixed_batch;

select is(
  (mixed_batch.outcomes -> 1 ->> 'said') is not null, true,
  'with the reason carried home to whoever is debugging the extractor'
) from mixed_batch;

select is(
  mixed_batch.outcomes -> 0 ->> 'outcome', 'unchanged',
  'while its neighbours go on standing'
) from mixed_batch;

select * from finish();
rollback;
