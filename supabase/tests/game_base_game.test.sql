-- The game a mutator is built on (#546), as submit_game_facts stores it on
-- public.game.base_game and public.game_browse publishes it.

begin;
select plan(9);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('99999999-9999-9999-9999-999999999999', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

reset role;
set local role service_role;

-- A submission naming a base game, and one that does not.
select public.submit_game_facts($j$
  {"shortname": "BAR-Rush", "release": "1.0.0", "base_game": "BAR",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select public.submit_game_facts($j$
  {"shortname": "BAR", "release": "1.0.0",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select is(
  (select base_game from public.game where shortname = 'BAR-Rush'), 'BAR',
  'a submission with a base game stores it'
);
select is(
  (select base_game from public.game where shortname = 'BAR'), null,
  'a submission without one stores null'
);

-- The base game need not be on the hub: there is no row called "Missing".
select public.submit_game_facts($j$
  {"shortname": "Orphan", "release": "1.0.0", "base_game": "Missing",
   "units": []}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select is(
  (select base_game from public.game where shortname = 'Orphan'), 'Missing',
  'a base game the hub does not hold yet is stored all the same'
);

-- A later report that does not name it leaves it alone.
select public.submit_game_facts($j$
  {"shortname": "BAR-Rush", "release": "1.1.0",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select is(
  (select base_game from public.game where shortname = 'BAR-Rush'), 'BAR',
  'a later report without a base game leaves the held one alone'
);

-- A later report that names another one replaces it.
select public.submit_game_facts($j$
  {"shortname": "BAR-Rush", "release": "1.2.0", "base_game": "ZK",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select is(
  (select base_game from public.game where shortname = 'BAR-Rush'), 'ZK',
  'a report naming a different base game replaces the held one'
);

-- A blank one is the same as none.
select public.submit_game_facts($j$
  {"shortname": "BAR-Rush", "release": "1.3.0", "base_game": "   ",
   "units": [{"unit": {"name": "armcom"}, "facts_digest": "d1"}]}
$j$::jsonb, '99999999-9999-9999-9999-999999999999');

select is(
  (select base_game from public.game where shortname = 'BAR-Rush'), 'ZK',
  'a blank base game does not clear the held one'
);

-- The listing view publishes it.
select is(
  (select base_game from public.game_browse where shortname = 'BAR-Rush'), 'ZK',
  'game_browse publishes the base game'
);
select is(
  (select base_game from public.game_browse where shortname = 'BAR'), null,
  'game_browse publishes null for a game that is not a mutator'
);

-- The column refuses a blank or oversized value however it is written.
select throws_ok(
  $q$ update public.game set base_game = repeat('x', 65) where shortname = 'BAR' $q$,
  '23514',
  null,
  'a base game past 64 characters is refused by the table'
);

select * from finish();
rollback;
