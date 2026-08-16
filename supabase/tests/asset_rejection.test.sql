-- A rejection is a state with a reason, and one of the two reasons is final
-- (issue #115).
--
-- Three things are being proved here and only the first is ordinary.
--
-- The kind is recorded, on every rejected row and on no other row, so the
-- audit trail can tell "we took down illegal content" from "we took down a bad
-- upload" without anybody having to remember which it was.
--
-- A safety rejection cannot be undone by anything the hub can do. Not by the
-- moderation functions, not by the secret key, and not by an update written by
-- hand as the table owner. That last one is the interesting case, because the
-- whole argument for putting the rule in a trigger rather than in the
-- application is that the application is not the only thing that writes here.
--
-- And every decision lands in public.asset_event with the person who made it,
-- read out of the session's own token rather than out of anything a caller
-- supplied. A log that records the decision and not the decider does not
-- answer the question the issue asks.
--
-- The row ids are written out rather than looked up, because most of this file
-- runs as `authenticated`, where asset_read_approved hides every row that is
-- not approved. A subquery for an id would quietly come back null and the
-- assertions would pass for the wrong reason.

begin;
select plan(32);

create extension if not exists pgtap with schema extensions;

-- An uploader and a moderator. The uploader is signed in and holds nothing,
-- which is what makes the refusals below mean something.
insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('33333333-3333-3333-3333-333333333333', 'can_moderate');

-- Three pending uploads from the same account: one that turns out to be junk,
-- one that turns out to be the reason this issue exists, and one that is fine.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values
  ('0f8fad5b-0000-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-j', 'enc-junk', 'webp-lossless-256', 'unit/bar/armsolar/buildpic/enc-junk.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-0000-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-n', 'enc-nasty', 'webp-lossless-256', 'unit/bar/armllt/buildpic/enc-nasty.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-0000-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-f', 'enc-fine', 'webp-lossless-256', 'unit/bar/armcom/buildpic/enc-fine.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111');

-- Where each of them came from, which is the third thing a report needs.
insert into public.asset_upload_ip (asset_id, ip)
select id, '203.0.113.7'::inet from public.asset
where hash in ('enc-junk', 'enc-nasty', 'enc-fine');

-- An ordinary upload arriving pending is not a decision, so nothing is logged
-- about it. The asset row already says who and when.
select is(
  (select count(*) from public.asset_event)::int, 0,
  'an upload landing in the queue is not an event, because nothing was decided'
);

-- ## The kind is tied to the state, both ways

select throws_ok(
  $$update public.asset set moderation = 'rejected' where hash = 'enc-fine'$$,
  '23514',
  null,
  'a row cannot be rejected without saying which kind of rejection it is'
);

select throws_ok(
  $$update public.asset set rejection_kind = 'safety' where hash = 'enc-fine'$$,
  '23514',
  null,
  'and a pending row cannot carry a reason for a rejection that has not happened'
);

-- ## Who may decide

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.reject_asset('0f8fad5b-0000-4000-8000-00000000000a', 'editorial')$$,
  '42501',
  null,
  'the uploader cannot reject their own picture, capability being the only way in'
);

select throws_ok(
  $$select public.approve_assets(ARRAY['0f8fad5b-0000-4000-8000-00000000000c'::uuid])$$,
  '42501',
  null,
  'nor approve it'
);

select throws_ok(
  $$select public.return_asset('0f8fad5b-0000-4000-8000-00000000000a')$$,
  '42501',
  null,
  'nor put anything back in the queue'
);

-- ## The moderator, deciding

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.reject_asset('0f8fad5b-0000-4000-8000-00000000000a', 'editorial'),
  true,
  'a moderator rejects the junk one as an editorial call'
);

select is(
  public.reject_asset('0f8fad5b-0000-4000-8000-00000000000b', 'safety'),
  true,
  'and the other one on safety grounds'
);

select throws_ok(
  $$select public.reject_asset('0f8fad5b-0000-4000-8000-00000000000c', 'unrecorded')$$,
  '23514',
  null,
  'unrecorded is the backfill for older rows and not something to decide today'
);

select throws_ok(
  $$select public.reject_asset('0f8fad5b-0000-4000-8000-00000000000c', 'spam')$$,
  '23514',
  null,
  'and a kind nobody has defined is refused rather than stored'
);

select is(
  public.approve_assets(ARRAY['0f8fad5b-0000-4000-8000-00000000000c'::uuid]),
  1,
  'the fine one is approved'
);

select is(
  public.approve_assets(ARRAY['0f8fad5b-0000-4000-8000-00000000000a'::uuid]),
  0,
  'and a stale tab cannot approve something another moderator has since rejected'
);

-- ## A safety rejection is final

select is(
  public.return_asset('0f8fad5b-0000-4000-8000-00000000000b'),
  false,
  'a moderator cannot put a safety rejection back in the queue'
);

select is(
  public.return_asset('0f8fad5b-0000-4000-8000-00000000000a'),
  true,
  'and can put an editorial one back, which is what telling them apart is for'
);

-- The secret key, which is what the upload route and every server side write
-- holds. It carries bypassrls and every grant this schema hands out, and it
-- still cannot do this.
reset role;
set local role service_role;
set local request.jwt.claims = '';

select is(
  (select rejection_kind from public.asset where hash = 'enc-junk'),
  null,
  'the returned one is waiting again with no reason attached to it'
);

select is(
  (select rejection_kind from public.asset where hash = 'enc-nasty'),
  'safety',
  'and the two rejections were two different rows, which is the whole point of the kind'
);

select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator'
    where hash = 'enc-nasty'$$,
  '23514',
  null,
  'the secret key cannot approve a safety rejection'
);

select throws_ok(
  $$update public.asset set rejection_kind = 'editorial' where hash = 'enc-nasty'$$,
  '23514',
  null,
  'nor quietly re-label it as somebody having made a judgement call'
);

select throws_ok(
  $$update public.asset set path = 'unit/bar/armllt/buildpic/something-else.webp'
    where hash = 'enc-nasty'$$,
  '23514',
  null,
  'nor point the row at different bytes, which would leave the evidence naming nothing'
);

-- The table owner, by hand, which is the case that decides whether the rule
-- lives in the application or in the database.
reset role;

select throws_ok(
  $$update public.asset set moderation = 'pending', rejection_kind = null
    where hash = 'enc-nasty'$$,
  '23514',
  null,
  'and neither can an update written by hand as the owner of the table'
);

select throws_ok(
  $$delete from public.asset where hash = 'enc-nasty'$$,
  '23503',
  null,
  'nor can the row be deleted, since the audit trail refers to it'
);

-- ## What the log says

select is(
  (select action || ':' || coalesce(rejection_kind, '-') from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000b'),
  'rejected:safety',
  'the safety rejection is in the log as a safety rejection'
);

select is(
  (select actor from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000b'),
  '33333333-3333-3333-3333-333333333333'::uuid,
  'named to the moderator who made it, out of the session and not out of a payload'
);

select is(
  (select uploader from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000b'),
  '11111111-1111-1111-1111-111111111111'::uuid,
  'and to the account it came from, kept as a plain uuid so closing that account does not erase it'
);

select is(
  (select array_agg(action order by id) from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000a'),
  ARRAY['rejected', 'returned'],
  'an editorial rejection and the undoing of it are both in the log, in order'
);

-- The trusted paths, which are the ones the issue asks to have logged. Nothing
-- writes these yet: #110 seeds the corpus and nobody holds
-- can_publish_unreviewed, so the trigger is what makes the log complete before
-- either of them is written rather than after somebody remembers.
insert into public.asset (id, map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-0000-4000-8000-00000000000d', 'Tangerine 1.1', 'minimap', 'src-s', 'enc-seeded', 'webp-q80-512', 'map/tangerine/minimap/enc-seeded.webp', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'tangerine_1.1.sd7', 'approved', 'seed', '33333333-3333-3333-3333-333333333333');

select is(
  (select action from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000d'),
  'seeded',
  'a row written straight into the corpus is logged as seeded without anything asking it to be'
);

-- ## The log is append only, and the addresses are write only

reset role;
set local role service_role;

select throws_ok(
  $$insert into public.asset_event (asset_id, action)
    values ('0f8fad5b-0000-4000-8000-00000000000c', 'approved')$$,
  '42501',
  null,
  'nothing holding the secret key can write the log directly'
);

select throws_ok(
  $$delete from public.asset_event$$,
  '42501',
  null,
  'nor tidy up after itself'
);

select throws_ok(
  $$select count(*) from public.asset_upload_ip$$,
  '42501',
  null,
  'and cannot read back an uploader address, which nothing in the hub ever needs to'
);

select lives_ok(
  $$insert into public.asset_upload_ip (asset_id, ip)
    values ('0f8fad5b-0000-4000-8000-00000000000a', '198.51.100.9')$$,
  'the upload route records one, which is all it may do with them'
);

-- ## Retention
--
-- Kept while the picture is pending or rejected, and purged the moment it is
-- approved. The address exists to support a report about an upload the hub
-- refused, and an approved picture is not that.

reset role;

select is(
  (select count(*) from public.asset_upload_ip
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000c')::int,
  0,
  'approving a picture throws away where it came from'
);

select is(
  (select count(*) from public.asset_upload_ip
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000b')::int,
  1,
  'and a safety rejection keeps it, which is the case any of this is for'
);

select * from finish();
rollback;
