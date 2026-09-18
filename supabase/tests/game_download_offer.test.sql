-- What a held download source can and cannot reach, and who turns one into a
-- real one (#408).
--
-- A download source is the one thing a facts submission carries that is not
-- descriptive. A unit's armour value is a reading of an archive; a download
-- source is an instruction to fetch and run code, offered by anybody who can
-- submit facts for a game they have installed. So the assertions that matter
-- here are the negative ones: that nobody reads a held source, that nobody
-- without the right decides one, and that offering the same thing twice buys
-- nothing.
--
-- Run as the roles PostgREST actually uses, because a grant and a policy are
-- separate layers and a test that runs as the owner of the database proves
-- neither.

begin;
select plan(21);

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
  ('0f8fad5b-0008-4000-8000-000000000042', 'MF', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
  ('0f8fad5b-0008-4000-8000-000000000043', 'BA', null);

-- MF already names one place to look, put there by its owner.
insert into public.game_download_source (game_id, kind, value, sort_order)
values ('0f8fad5b-0008-4000-8000-000000000042', 'rapid', 'metalfactions:stable', 0);

-- ## What the table itself refuses

select throws_ok(
  $$insert into public.game_download_offer (game_id, kind, value, offered_by)
    values ('0f8fad5b-0008-4000-8000-000000000042', 'torrent', 'whatever',
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')$$,
  '23514',
  null,
  'an offered kind outside the three is refused, the same three the source table holds'
);

select throws_ok(
  $$insert into public.game_download_offer (game_id, kind, value, filename, offered_by)
    values ('0f8fad5b-0008-4000-8000-000000000042', 'github', 'owner/repo', 'mf.sdz',
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')$$,
  '23514',
  null,
  'a filename on an offered github source is refused, since the release names the file'
);

-- Held and undecided are one state. An accepted row with nothing against it
-- would be indistinguishable from an update that half landed.
select throws_ok(
  $$insert into public.game_download_offer (game_id, kind, value, offered_by, state)
    values ('0f8fad5b-0008-4000-8000-000000000042', 'rapid', 'mine:stable',
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'accepted')$$,
  '23514',
  null,
  'an offer cannot read as decided with nobody and no time against it'
);

-- ## Offering
--
-- Three sources for one game: one it already holds, and two it does not.
select is(
  public.offer_game_download_sources(
    'MF',
    '[{"kind": "rapid", "value": "metalfactions:stable"},
      {"kind": "github", "value": "springraaar/metal_factions", "asset": "metal_factions"},
      {"kind": "url", "value": "https://example.test/mf.sdz", "filename": "mf.sdz"}]'::jsonb,
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
  ),
  2,
  'a source the game already holds is not offered, and the two it does not are'
);

-- The whole point of the design: offering writes nowhere a reader looks.
select is(
  (select count(*)::integer from public.game_download_source
    where game_id = '0f8fad5b-0008-4000-8000-000000000042'),
  1,
  'offering never writes a download source, so the owner''s list is the one they left'
);

-- A client sweeping on a timer sends the same list every run.
select is(
  public.offer_game_download_sources(
    'MF',
    '[{"kind": "github", "value": "springraaar/metal_factions", "asset": "metal_factions"}]'::jsonb,
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
  ),
  0,
  'offering a source already held adds nothing, so a sweep cannot grow the queue'
);

select is(
  (select count(*)::integer from public.game_download_offer where state = 'held'),
  2,
  'and the second offer neither promoted the first nor sat down beside it'
);

select is(
  public.offer_game_download_sources('NOSUCHGAME', '[{"kind": "rapid", "value": "x:stable"}]'::jsonb,
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'),
  0,
  'a shortname the hub does not hold offers nothing rather than raising'
);

-- The two ids, read here while the session is still the owner of the database.
-- Every assertion below runs as a role with no grant on this table, so a
-- subquery looking an id up would fail on the grant rather than on the thing
-- being tested - which is itself the point several of them make.
select id as github_offer from public.game_download_offer where kind = 'github' \gset
select id as url_offer from public.game_download_offer where kind = 'url' \gset

-- ## Nobody holding a publishable key reads a held source
--
-- No grant and no policy, so the read fails at the grant, before row level
-- security is asked anything. That is deliberate: a held source must not be one
-- predicate away from a reader.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select throws_ok(
  $$select count(*) from public.game_download_offer$$,
  '42501',
  null,
  'a signed out reader cannot select a held offer at all'
);

-- The read path that would actually publish one: game_browse.downloads is what
-- GET /api/v1/games hands every caller.
select is(
  (select jsonb_array_length(downloads) from public.game_browse where shortname = 'MF'),
  1,
  'the browse view still publishes only the one source a person wrote'
);

select throws_ok(
  $$select public.decide_game_download_offer(1, true)$$,
  '42501',
  null,
  'a signed out reader cannot even call the deciding function'
);

-- ## A stranger decides nothing
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select throws_ok(
  $$select count(*) from public.game_download_offer$$,
  '42501',
  null,
  'a signed in stranger cannot select a held offer either, not even the one they offered'
);

select throws_ok(
  format($$select public.decide_game_download_offer(%s, true)$$, :url_offer),
  '42501',
  null,
  'a signed in stranger cannot accept their own offer'
);

-- Offering is the facts route's, spent with the secret key. A browser session
-- reaching it would be able to fill the queue without ever posting facts.
select throws_ok(
  $$select public.offer_game_download_sources('MF', '[]'::jsonb,
      'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')$$,
  '42501',
  null,
  'nothing holding a publishable key can put an offer in the queue'
);

-- Not even a moderator reads the table through their own session, which is why
-- the queue page spends the secret key after asking is_moderator() first.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select throws_ok(
  $$select count(*) from public.game_download_offer$$,
  '42501',
  null,
  'a moderator reads the queue with the secret key, never through their own session'
);

-- ## The owner accepts, and it lands at the end
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select ok(
  public.decide_game_download_offer(:github_offer, true),
  'the game''s owner accepts an offer for their own game'
);

reset role;
select results_eq(
  $$select kind, value, asset, sort_order from public.game_download_source
    where game_id = '0f8fad5b-0008-4000-8000-000000000042' order by sort_order$$,
  $$values ('rapid'::text, 'metalfactions:stable'::text, null::text, 0),
           ('github'::text, 'springraaar/metal_factions'::text, 'metal_factions'::text, 1)$$,
  'an accepted offer is appended whole, after the source the owner already had'
);

select is(
  (select state from public.game_download_offer where kind = 'github'),
  'accepted',
  'and the offer is marked accepted rather than left in the queue'
);

-- A second decision on a decided offer is not an error, it is nothing to do.
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select ok(
  not public.decide_game_download_offer(:github_offer, true),
  'deciding an offer somebody already decided is false rather than a second source'
);

-- ## A moderator decides a game they do not own
reset role;
insert into public.game_download_offer (game_id, kind, value, offered_by)
values ('0f8fad5b-0008-4000-8000-000000000043', 'rapid', 'ba:stable',
        'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb')
returning id as ba_offer \gset

set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select ok(
  public.decide_game_download_offer(:ba_offer, false),
  'a moderator turns down an offer on a game nobody owns'
);

reset role;
select is(
  (select count(*)::integer from public.game_download_source
    where game_id = '0f8fad5b-0008-4000-8000-000000000043'),
  0,
  'turning an offer down writes no download source'
);

select * from finish();
rollback;
