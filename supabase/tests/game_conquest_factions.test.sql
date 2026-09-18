-- A game's own conquest faction list (#393), run as the roles PostgREST
-- actually uses.
--
-- Two things the issue is emphatic about, proved here rather than trusted to
-- the action that writes them: two factions can name the same in-game side,
-- because nothing here is unique on (game_id, side) the way game_faction is
-- on (game_id, key); and a facts submission, which replaces game_faction
-- wholesale, leaves this column alone.

begin;
select plan(9);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('dddddddd-dddd-dddd-dddd-dddddddddddd', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('ffffffff-ffff-ffff-ffff-ffffffffffff', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('ffffffff-ffff-ffff-ffff-ffffffffffff', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0009-4000-8000-000000000001', 'BA', 'dddddddd-dddd-dddd-dddd-dddddddddddd');

-- The owner names four factions across two in-game sides: two houses per
-- side, which is exactly the shape #393 says game_faction cannot hold.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"dddddddd-dddd-dddd-dddd-dddddddddddd","role":"authenticated"}';

select lives_ok(
  $$update public.game set conquest_factions = '[
      {"name": "House Arm-1", "color": "#2f7dff", "side": "ARM"},
      {"name": "House Arm-2", "side": "ARM"},
      {"name": "House Core-1", "side": "CORE"},
      {"name": "House Core-2", "side": "CORE"}
    ]'::jsonb
    where shortname = 'BA'$$,
  'the owner authors an ordered list with two factions sharing one side'
);

select is(
  (select jsonb_array_length(conquest_factions) from public.game where shortname = 'BA'),
  4,
  'all four rows are held, none refused for repeating a side'
);

select is(
  (select conquest_factions -> 0 ->> 'name' from public.game where shortname = 'BA'),
  'House Arm-1',
  'and the order the owner gave is the order stored'
);

-- A stranger's edit touches nothing, the same answer editGameDetails gets.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee","role":"authenticated"}';

select lives_ok(
  $$update public.game set conquest_factions = '[]'::jsonb where shortname = 'BA'$$,
  'a stranger''s edit touches no rows rather than erroring'
);

select is(
  (select jsonb_array_length(conquest_factions) from public.game where shortname = 'BA'),
  4,
  'because the policy filtered the row out, the list is untouched'
);

-- A moderator edits it too, on the same grant an owner writes through (#350).
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"ffffffff-ffff-ffff-ffff-ffffffffffff","role":"authenticated"}';

select lives_ok(
  $$update public.game set conquest_factions = '[{"name": "Reworked"}]'::jsonb where shortname = 'BA'$$,
  'a moderator edits the faction list on a game somebody else owns'
);

select is(
  (select conquest_factions from public.game where shortname = 'BA'),
  '[{"name": "Reworked"}]'::jsonb,
  'and the list changed'
);

-- A facts submission for the same shortname must leave the authored list
-- alone: it is a person's words, not a fact submit_game_facts reports.
reset role;
set local role service_role;

select public.submit_game_facts($j$
  {
    "shortname": "BA",
    "release": "1.9.0",
    "factions": [{"key": "armada", "name": "Armada"}],
    "units": [{"unit": {"name": "armcom"}, "facts_digest": "d-armcom"}]
  }
$j$::jsonb, 'ffffffff-ffff-ffff-ffff-ffffffffffff');

select is(
  (select conquest_factions from public.game where shortname = 'BA'),
  '[{"name": "Reworked"}]'::jsonb,
  'submitting facts replaces game_faction but leaves the authored list alone'
);

select is(
  (select gf.name from public.game_faction gf join public.game g on g.id = gf.game_id
    where g.shortname = 'BA' and gf.key = 'armada'),
  'Armada',
  'while game_faction itself did take the submission, proving the two never shared a table'
);

select * from finish();
rollback;
