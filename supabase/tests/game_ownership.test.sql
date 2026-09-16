-- Who may ask to own a game, who decides, and what an owner may edit once they
-- are one (#229), run as the roles PostgREST actually uses.
--
-- The state machine's rules live in constraints and policies, so they are
-- proved here rather than trusted to the actions that call them: one open ask
-- per person per game, nobody asking as somebody else, nobody deciding but a
-- moderator, and an owner editing exactly the columns the issue names and
-- nothing else.

begin;
select plan(37);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'author@example.test', '{"full_name": "Game Author"}'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'other@example.test', '{}'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test', '{}');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.game (id, shortname) values ('0f8fad5b-0006-4000-8000-000000000001', 'BA');
insert into public.game_unit (game_id, unit_name, facts_digest)
values ('0f8fad5b-0006-4000-8000-000000000001', 'armcom', 'd1');

-- The author asks.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$insert into public.game_ownership_request (game_id, requested_by, note)
    values ('0f8fad5b-0006-4000-8000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'I make this game')$$,
  'a signed in account asks for itself'
);

select is(
  (select requested_by_name from public.game_ownership_request limit 1),
  'Game Author',
  'and the name on it comes from the profile, not from the form'
);

select throws_ok(
  $$insert into public.game_ownership_request (game_id, requested_by, note)
    values ('0f8fad5b-0006-4000-8000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'again')$$,
  '23505',
  null,
  'a second open ask for the same game is refused'
);

select throws_ok(
  $$insert into public.game_ownership_request (game_id, requested_by)
    values ('0f8fad5b-0006-4000-8000-000000000001', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')$$,
  '42501',
  null,
  'nobody asks as somebody else'
);

select is(
  (select count(*) from public.game_ownership_request)::int, 1,
  'a requester reads their own ask back'
);

-- A second account sees nothing of it.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select is(
  (select count(*) from public.game_ownership_request)::int, 0,
  'who wants a game is not a listing'
);

-- A non-moderator's decision is not an error either: the policy filters every
-- row out and the update succeeds over nothing. What matters is that no state
-- moved.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select lives_ok(
  $$update public.game_ownership_request set state = 'approved'$$,
  'somebody who is not a moderator decides nothing'
);

-- The moderator reads the queue and approves.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select is(
  (select count(*) from public.game_ownership_request where state = 'open')::int, 1,
  'a moderator sees the queue'
);

select is(
  (select state from public.game_ownership_request limit 1),
  'open',
  'and the failed decision left the ask open'
);

select lives_ok(
  $$update public.game_ownership_request
    set state = 'approved',
        decided_by = 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        decided_at = now()
    where game_id = '0f8fad5b-0006-4000-8000-000000000001'$$,
  'and decides it'
);

-- Ownership itself moves inside the decision action, which writes as
-- service_role; no policy hands owner_user_id to a browser.
reset role;
set local role service_role;

select lives_ok(
  $$select game_id, state, requested_by from public.game_ownership_request$$,
  'the decision action reads the ask as service_role'
);

select lives_ok(
  $$update public.game_ownership_request set decided_at = now()
    where game_id = '0f8fad5b-0006-4000-8000-000000000001'$$,
  'and writes the decision as service_role'
);

update public.game set owner_user_id = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' where shortname = 'BA';

-- Now the author owns BA, and can edit exactly what an owner edits.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$update public.game set display_name = 'Balanced Annihilation', description = 'The classic.', links = '[{"label":"Site","url":"https://example.test"}]'::jsonb
    where shortname = 'BA'$$,
  'an owner edits their game''s words'
);

select throws_ok(
  $$update public.game set shortname = 'XX' where shortname = 'BA'$$,
  '42501',
  null,
  'but identity is not theirs to change'
);

select throws_ok(
  $$update public.game set owner_user_id = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' where shortname = 'BA'$$,
  '42501',
  null,
  'and neither is who owns it'
);

-- A non-owner's edit is not an error: the policy filters the row out, so the
-- update succeeds over nothing. What matters is that nothing moved.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select lives_ok(
  $$update public.game set display_name = 'Mine Now', description = 'not mine' where shortname = 'BA'$$,
  'a non-owner''s edit touches no rows rather than erroring'
);

select is(
  (select row(display_name::text, description::text) from public.game where shortname = 'BA'),
  row('Balanced Annihilation'::text, 'The classic.'::text),
  'because the policy filtered the row out'
);

-- Snippets follow the same rule through the unit's game.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$update public.game_unit set snippet = 'The commander. Everything starts here.' where unit_name = 'armcom'$$,
  'the owner writes a snippet on their own game''s unit'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select lives_ok(
  $$update public.game_unit set snippet = 'nope' where unit_name = 'armcom'$$,
  'a non-owner''s snippet edit also touches nothing'
);

select is(
  (select snippet from public.game_unit where unit_name = 'armcom'),
  'The commander. Everything starts here.',
  'and the owner''s words are still there'
);

-- A moderator may do anything an owner may, on any game (#350): a game owned
-- by somebody else, and a hidden game nobody owns. Only the columns an owner
-- holds, because the grant is the same grant.
reset role;
set local role service_role;
insert into public.game (id, shortname, hidden_at)
values ('0f8fad5b-0006-4000-8000-000000000002', 'ZK', now());
insert into public.game_unit (game_id, unit_name, facts_digest)
values ('0f8fad5b-0006-4000-8000-000000000002', 'cloakcon', 'd2');

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select lives_ok(
  $$update public.game set display_name = 'BA, edited', description = 'A moderator wrote this.' where shortname = 'BA'$$,
  'a moderator edits the words on a game somebody else owns'
);

select is(
  (select row(display_name::text, description::text) from public.game where shortname = 'BA'),
  row('BA, edited'::text, 'A moderator wrote this.'::text),
  'and the words changed'
);

select throws_ok(
  $$update public.game set shortname = 'XX' where shortname = 'BA'$$,
  '42501',
  null,
  'but a moderator cannot change identity through the grant'
);

select throws_ok(
  $$update public.game set owner_user_id = 'cccccccc-cccc-cccc-cccc-cccccccccccc' where shortname = 'BA'$$,
  '42501',
  null,
  'nor who owns it'
);

select throws_ok(
  $$update public.game set logo_path = 'games/BA/logo.png' where shortname = 'BA'$$,
  '42501',
  null,
  'nor the image columns, which only the secret key writes'
);

select lives_ok(
  $$update public.game_unit set snippet = 'A moderator''s snippet.' where unit_name = 'armcom'$$,
  'a moderator writes a snippet on a game somebody else owns'
);

select is(
  (select snippet from public.game_unit where unit_name = 'armcom'),
  'A moderator''s snippet.',
  'and the snippet changed'
);

select lives_ok(
  $$update public.game set description = 'Hidden, unowned, described.' where shortname = 'ZK'$$,
  'a moderator edits a hidden game nobody owns'
);

select is(
  (select description from public.game where shortname = 'ZK'),
  'Hidden, unowned, described.',
  'and reads the change back'
);

select lives_ok(
  $$update public.game_unit set snippet = 'Cloaks.' where unit_name = 'cloakcon'$$,
  'a moderator writes a snippet on that hidden game'
);

select is(
  (select snippet from public.game_unit where unit_name = 'cloakcon'),
  'Cloaks.',
  'and the snippet is there'
);

-- A signed in account that is neither owner nor moderator still changes
-- nothing on either game.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select lives_ok(
  $$update public.game set description = 'stranger' where shortname in ('BA', 'ZK')$$,
  'a stranger''s edit after a moderator''s touches no rows'
);

select lives_ok(
  $$update public.game_unit set snippet = 'stranger' where unit_name in ('armcom', 'cloakcon')$$,
  'nor does a stranger''s snippet edit'
);

reset role;
set local role service_role;

select is(
  (select array_agg(description order by shortname) from public.game where shortname in ('BA', 'ZK')),
  array['A moderator wrote this.', 'Hidden, unowned, described.'],
  'both games keep the moderator''s words'
);

select is(
  (select array_agg(snippet order by unit_name) from public.game_unit where unit_name in ('armcom', 'cloakcon')),
  array['A moderator''s snippet.', 'Cloaks.'],
  'and both snippets'
);

-- An anonymous visitor holds nothing at all on the new table.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select throws_ok(
  $$select count(*) from public.game_ownership_request$$,
  '42501',
  null,
  'the queue is not served to a browser with no session'
);

select throws_ok(
  $$insert into public.game_ownership_request (game_id, requested_by)
    values ('0f8fad5b-0006-4000-8000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')$$,
  '42501',
  null,
  'and asking needs an account'
);

select * from finish();
rollback;
