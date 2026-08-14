-- The asset access model, run as the roles PostgREST actually uses (issue
-- #102). `anon` is a visitor with no session, `authenticated` is somebody
-- signed in with Discord, and both of them hold the publishable key, which is
-- inlined into the browser bundle and served as JSON by #48. `service_role` is
-- the Vercel route and nothing else.
--
-- What is being proved is mostly negative, and the negatives are the point.
-- The Blob store is public, so an uploaded asset is reachable before anybody
-- reviews it, and the only thing keeping a pending picture out of sight is
-- that no query anybody can run returns its path. So a read that leaks one row
-- is not a cosmetic bug, and a write a client can reach sets moderation itself.
--
-- A grant and a policy are two layers and either one shut is enough, which is
-- why table_privileges.test.sql asserts the grants directly as well. #59 found
-- production holding grants these migrations never wrote, and behavioural
-- tests alone cannot see that.

begin;
select plan(26);

create extension if not exists pgtap with schema extensions;

-- Every count below names the three hashes this file inserts rather than
-- counting the table (issue #140). Counting the table passes on a fresh
-- database and fails on any machine where somebody has exercised the upload
-- route, and the failure then names the access rules rather than the leftover
-- row. Nothing can delete an asset row through the API by design, so a stale
-- local row is not something a run can clear up after itself.

-- Somebody who uploaded one of the rows below, so "not readable" can be told
-- apart from "not readable by other people".
insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

-- One of each moderation state. The pending row is the uploader's own.
insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, moderation, approval_source)
values
  ('bar', 'armsolar', 'buildpic', 'src-a', 'enc-approved', 'buildpic-q80', 'unit/bar/armsolar/buildpic/enc-approved.webp', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'seed');

insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values
  ('bar', 'armsolar', 'render:front', 'src-p', 'enc-pending', 'render-q80', 'unit/bar/armsolar/render-front/enc-pending.webp', 'rendered', 'image/webp', 8192, 256, 256, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111');

insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive, moderation)
values
  ('Tangerine 1.1', 'minimap', 'src-r', 'enc-rejected', 'minimap-q80', 'map/tangerine/minimap/enc-rejected.webp', 'uploaded', 'image/webp', 40000, 512, 512, 8192, 8192, 'tangerine_1.1.sd7', 'rejected');

-- A visitor with no account at all.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.asset
    where hash in ('enc-approved', 'enc-pending', 'enc-rejected'))::int, 1,
  'a visitor sees the approved asset and neither of the other two'
);

select is(
  (select count(*) from public.asset where hash = 'enc-pending')::int, 0,
  'asking for a pending row by name does not produce it either'
);

select is(
  (select path from public.asset
    where hash in ('enc-approved', 'enc-pending', 'enc-rejected')),
  'unit/bar/armsolar/buildpic/enc-approved.webp',
  'and the approved row is whole, since the picture is already public'
);

-- A refusal rather than a write that affects nothing. A silently empty write
-- looks like success to the caller, and the caller here is somebody probing.
select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, moderation, approval_source)
    values ('bar', 'armcom', 'buildpic', 'src-x', 'enc-x', 'buildpic-q80', 'unit/bar/armcom/buildpic/enc-x.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'nothing.sdz', 'approved', 'seed')$$,
  '42501',
  null,
  'a visitor cannot write itself an approved asset'
);

select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator'$$,
  '42501',
  null,
  'nor approve one that is waiting'
);

select throws_ok(
  $$delete from public.asset$$,
  '42501',
  null,
  'nor remove one'
);

select throws_ok(
  $$select count(*) from public.asset_licence$$,
  '42501',
  null,
  'the licence decisions are not served to a browser'
);

select throws_ok(
  $$select count(*) from public.user_capability$$,
  '42501',
  null,
  'and who holds a capability is not either'
);

-- Signed in, and the uploader of the pending row.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from public.asset
    where hash in ('enc-approved', 'enc-pending', 'enc-rejected'))::int, 1,
  'an account reads exactly what a visitor reads, including its own pending upload, which it does not'
);

select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'bypass'
    where hash = 'enc-pending'$$,
  '42501',
  null,
  'an uploader cannot approve their own upload'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armcom', 'buildpic', 'src-y', 'enc-y', 'buildpic-q80', 'unit/bar/armcom/buildpic/enc-y.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'nothing.sdz')$$,
  '42501',
  null,
  'an upload goes through the route, so the client cannot write the row itself'
);

select throws_ok(
  $$delete from public.asset where hash = 'enc-pending'$$,
  '42501',
  null,
  'and cannot withdraw it afterwards either'
);

-- A moderator gains nothing here, and #114 has now decided that on purpose
-- rather than leaving it open. The contact sheet reads the queue server side
-- with the secret key, so `can_moderate` buys no extra row through PostgREST.
--
-- The reason is `path`. On a pending row it is a working URL into a public
-- store for bytes nobody has reviewed, and Blob's random suffix is the whole of
-- what keeps it out of sight (#131). A select policy here would make that
-- column readable with the publishable key by any browser holding a moderator
-- session, which puts the queue's one secret into a browser and cannot be taken
-- back: the path cannot be rotated without rewriting the object. Reading it
-- server side keeps the path on the server, and app/moderation/assets/[id]
-- re-asks is_moderator() for every thumbnail it serves.
--
-- So the two assertions below are the decision, not a placeholder for one.
reset role;
insert into public.user_capability (user_id, capability)
values ('11111111-1111-1111-1111-111111111111', 'can_moderate');

set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  public.is_moderator(), true,
  'this account really is a moderator'
);

select is(
  (select count(*) from public.asset
    where hash in ('enc-approved', 'enc-pending', 'enc-rejected'))::int, 1,
  'and still reads only the approved row, because the grid reads the queue as service_role instead'
);

select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator'
    where hash = 'enc-pending'$$,
  '42501',
  null,
  'and cannot approve one from a browser either, since approving is a server action and not a write a session makes'
);

-- The Vercel route, which holds the secret key and nothing in a browser does.
reset role;
set local role service_role;

select is(
  (select count(*) from public.asset
    where hash in ('enc-approved', 'enc-pending', 'enc-rejected'))::int, 3,
  'the route reads every row whatever its moderation state'
);

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
    values ('bar', 'armllt', 'buildpic', 'src-n', 'enc-new', 'buildpic-q80', 'unit/bar/armllt/buildpic/enc-new.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111')$$,
  'the route writes an upload, which is the only way one is ever written'
);

select lives_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator'
    where hash = 'enc-pending'$$,
  'and moves a row through the queue'
);

-- The other half of what the grid does (#114). Rejecting leaves
-- approval_source alone, which asset_approval_state_check permits and #115
-- depends on: on a rejected row the column reads how it was approved before it
-- was rejected, and is null when it never was.
select lives_ok(
  $$update public.asset set moderation = 'rejected' where hash = 'enc-new'$$,
  'and rejects one without having to say what approved it'
);

select throws_ok(
  $$delete from public.asset where hash = 'enc-rejected'$$,
  '42501',
  null,
  'the route cannot delete, because rejecting is an update and nothing else has a reason to'
);

select lives_ok(
  $$select count(*) from public.asset_licence$$,
  'the route reads the licence decision the publishing gate rests on'
);

select throws_ok(
  $$update public.asset_licence set redistribute_rendered = 'allowed'$$,
  '42501',
  null,
  'and cannot make one at runtime, since a decision is permanent and belongs in a reviewed migration'
);

select throws_ok(
  $$select count(*) from public.user_capability$$,
  '42501',
  null,
  'the secret key does not read the capability table, so no route can grant anything'
);

reset role;

-- The policy itself, named and shaped, so a second one added later without a
-- test is visible here rather than in production.
select is(
  (select array_agg(polname order by polname) from pg_policy
    where polrelid = 'public.asset'::regclass),
  ARRAY['asset_read_approved']::name[],
  'one policy on asset and no others'
);

select is(
  (select polcmd from pg_policy where polrelid = 'public.asset'::regclass), 'r'::"char",
  'and it is a read policy, so nothing it does can let a write through'
);

select is(
  (select relrowsecurity from pg_class where oid = 'public.asset'::regclass), true,
  'row level security is on, so the grant is not the only thing standing there'
);

select * from finish();
rollback;
