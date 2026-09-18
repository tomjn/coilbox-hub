-- Who may write a game's download sources, and what the table refuses (#396).
--
-- The download source used to be two columns on public.game, so the owner's
-- column grant was the whole rule and game_featured.test.sql proved it in one
-- line. A table needs more: the owner writes rows on a table they do not own a
-- column of, a stranger must not, and the two detail columns belong to one
-- kind each.
--
-- Run as the roles PostgREST actually uses, because a grant and a policy are
-- separate layers and a test that runs as the owner of the database proves
-- neither.

begin;
select plan(11);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values
  ('0f8fad5b-0007-4000-8000-000000000042', 'MF', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
  ('0f8fad5b-0007-4000-8000-000000000043', 'BA', null);

-- ## What the table itself refuses
--
-- The kind is closed at three, and a detail on a kind with no use for it is a
-- value nothing will ever read.
select throws_ok(
  $$insert into public.game_download_source (game_id, kind, value)
    values ('0f8fad5b-0007-4000-8000-000000000042', 'torrent', 'whatever')$$,
  '23514',
  null,
  'a kind outside the three is refused'
);

select throws_ok(
  $$insert into public.game_download_source (game_id, kind, value, asset)
    values ('0f8fad5b-0007-4000-8000-000000000042', 'rapid', 'mf:stable', 'mf.sdz')$$,
  '23514',
  null,
  'an asset on a rapid source is refused, since only a github source picks one'
);

select throws_ok(
  $$insert into public.game_download_source (game_id, kind, value, filename)
    values ('0f8fad5b-0007-4000-8000-000000000042', 'github', 'owner/repo', 'mf.sdz')$$,
  '23514',
  null,
  'a filename on a github source is refused, since the release names the file'
);

-- Closing a game takes its sources with it. They are facts about that game and
-- nothing else, unlike a map mirror, which outlives any one map.
select lives_ok(
  $$insert into public.game_download_source (game_id, kind, value, sort_order)
    values ('0f8fad5b-0007-4000-8000-000000000043', 'rapid', 'ba:stable', 0)$$,
  'a source can be written for a game with no owner'
);
delete from public.game where shortname = 'BA';
select is(
  (select count(*)::integer from public.game_download_source),
  0,
  'deleting a game deletes its download sources'
);

-- ## The owner writes the list
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$insert into public.game_download_source (game_id, kind, value, asset, sort_order)
    values ('0f8fad5b-0007-4000-8000-000000000042', 'github', 'springraaar/metal_factions', 'metal_factions', 0),
           ('0f8fad5b-0007-4000-8000-000000000042', 'rapid', 'metalfactions:stable', null, 1)$$,
  'the owner may write their own game''s download sources'
);

-- No update grant at all: a save replaces the list, because the order is part
-- of what is being edited and a reorder is not a set of row updates.
select throws_ok(
  $$update public.game_download_source set value = 'other:stable'$$,
  '42501',
  null,
  'nobody updates a source in place, not even the owner'
);

-- ## A stranger writes nothing
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select throws_ok(
  $$insert into public.game_download_source (game_id, kind, value)
    values ('0f8fad5b-0007-4000-8000-000000000042', 'rapid', 'mine:stable')$$,
  '42501',
  null,
  'a stranger may not add a source to somebody else''s game'
);

-- A delete a policy filters out is not an error, it is a delete over no rows.
-- That silence is exactly why the server action asks editableGame first.
delete from public.game_download_source;
select is(
  (select count(*)::integer from public.game_download_source),
  2,
  'a stranger''s delete takes nothing away'
);

-- ## A moderator writes any game's list
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

delete from public.game_download_source;
select is(
  (select count(*)::integer from public.game_download_source),
  0,
  'a moderator may clear a game they do not own'
);

-- ## Everybody reads it
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select jsonb_array_length(downloads) from public.game_browse where shortname = 'MF'),
  0,
  'anon reads the downloads array through the browse view, empty rather than null'
);

select * from finish();
rollback;
