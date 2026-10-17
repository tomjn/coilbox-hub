-- Reviewing a source disagreement on a picture that is already approved (issue
-- #149).
--
-- The contact sheet rings a pending tile. An approved picture is not in the
-- queue, so a second account reporting different bytes for it was recorded and
-- shown to nobody. Two functions close that: one lists the approved pictures
-- with an open disagreement, one marks a disagreement as looked at.
--
-- What matters is who may call them and what they leave alone. Only a
-- moderator sees the list or clears an entry, and neither function moves the
-- picture, because a stranger's report must not be able to de-publish someone
-- else's approved picture. Clearing an entry is logged in public.asset_event
-- with the moderator behind it, like every other moderation action.

begin;
select plan(22);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'reporter@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test'),
  ('44444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test');

insert into public.user_capability (user_id, capability)
values ('33333333-3333-3333-3333-333333333333', 'can_moderate');

-- One approved picture, one still pending and one rejected, each with a
-- disagreement recorded against it. Only the approved one belongs on the list.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by, moderation, approval_source)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar', 'armsolar', 'buildpic', 'src-one', 'enc-one', 'webp-lossless-256', 'unit/bar/armsolar/buildpic/enc-one.webp', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111', 'approved', 'moderator');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values ('0f8fad5b-0000-4000-8000-0000000000a3', 'bar', 'armllt', 'buildpic', 'src-three', 'enc-three', 'webp-lossless-256', 'unit/bar/armllt/buildpic/enc-three.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by, moderation, rejection_kind)
values ('0f8fad5b-0000-4000-8000-0000000000a2', 'bar', 'armcom', 'buildpic', 'src-two', 'enc-two', 'webp-lossless-256', 'unit/bar/armcom/buildpic/enc-two.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111', 'rejected', 'editorial');

insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values
  ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-other', '22222222-2222-2222-2222-222222222222'),
  ('0f8fad5b-0000-4000-8000-0000000000a3', 'bar_1.2.sdz', 'src-three', 'src-other', '22222222-2222-2222-2222-222222222222'),
  ('0f8fad5b-0000-4000-8000-0000000000a2', 'bar_1.2.sdz', 'src-two', 'src-other', '22222222-2222-2222-2222-222222222222');

-- ## Who may call them

select function_privs_are('public', 'approved_source_conflicts', ARRAY['integer'], 'anon',
  ARRAY[]::name[], 'anon holds no grant on the list');
select function_privs_are('public', 'approved_source_conflicts', ARRAY['integer'], 'authenticated',
  ARRAY['EXECUTE'], 'authenticated may call the list, and the body decides');
select function_privs_are('public', 'acknowledge_source_conflict', ARRAY['bigint'], 'anon',
  ARRAY[]::name[], 'anon holds no grant on acknowledging');
select function_privs_are('public', 'acknowledge_source_conflict', ARRAY['bigint'], 'authenticated',
  ARRAY['EXECUTE'], 'authenticated may call acknowledging, and the body decides');

set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select throws_ok(
  $$select * from public.approved_source_conflicts(10)$$,
  '42501',
  null,
  'a signed out visitor cannot read the list'
);

select throws_ok(
  $$select public.acknowledge_source_conflict(1)$$,
  '42501',
  null,
  'a signed out visitor cannot clear an entry'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated"}';

select throws_ok(
  $$select * from public.approved_source_conflicts(10)$$,
  '42501',
  'the list is for moderators',
  'a signed in account without can_moderate cannot read the list'
);

select throws_ok(
  $$select public.acknowledge_source_conflict(1)$$,
  '42501',
  'clearing a disagreement needs can_moderate',
  'and cannot clear an entry'
);

-- ## What the moderator sees

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  (select count(*) from public.approved_source_conflicts(10))::int, 1,
  'a moderator sees the approved picture and not the pending or rejected ones'
);

select is(
  (select held_source_hash || ' vs ' || reported_source_hash || ' in ' || source_archive
     from public.approved_source_conflicts(10)),
  'src-one vs src-other in bar_1.2.sdz',
  'with both hashes and the archive they disagree about'
);

select is(
  (select reported_by || ' / ' || uploaded_by from public.approved_source_conflicts(10)),
  '22222222-2222-2222-2222-222222222222 / 11111111-1111-1111-1111-111111111111',
  'and who reported the new bytes and who uploaded the held ones'
);

select is(
  (select asset_id from public.approved_source_conflicts(10)),
  '0f8fad5b-0000-4000-8000-0000000000a1'::uuid,
  'and which picture it is, so the page can link to it'
);

-- The page size is the caller's, so the page can pass the moderation queue's
-- own constant rather than the database holding a second number.
reset role;
insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-third', '44444444-4444-4444-4444-444444444444');
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  (select count(*) from public.approved_source_conflicts(1))::int, 1,
  'the limit the caller passes is honoured'
);

select is(
  (select total from public.approved_source_conflicts(1)), 2::bigint,
  'and the total says there are more than the page shows'
);

-- ## Acknowledging

select is(
  public.acknowledge_source_conflict(
    (select conflict_id from public.approved_source_conflicts(10) where reported_source_hash = 'src-other')
  ),
  true,
  'a moderator clears an entry'
);

select is(
  (select count(*) from public.approved_source_conflicts(10))::int, 1,
  'which then leaves the list'
);

select is(
  (select reported_source_hash from public.approved_source_conflicts(10)),
  'src-third',
  'while a different set of bytes reported against the same picture stays'
);

select is(
  public.acknowledge_source_conflict(
    (select conflict_id from public.approved_source_conflicts(10) where reported_source_hash = 'src-third')
  ),
  true,
  'and can be cleared separately'
);

select is(
  public.acknowledge_source_conflict(0), false,
  'an entry that does not exist, or is already cleared, reports false'
);

reset role;

select is(
  (select moderation from public.asset where id = '0f8fad5b-0000-4000-8000-0000000000a1'),
  'approved',
  'clearing leaves the picture approved'
);

select is(
  (select array_agg(action || ':' || actor order by id) from public.asset_event
    where asset_id = '0f8fad5b-0000-4000-8000-0000000000a1' and action = 'conflict_acknowledged'),
  ARRAY[
    'conflict_acknowledged:33333333-3333-3333-3333-333333333333',
    'conflict_acknowledged:33333333-3333-3333-3333-333333333333'
  ],
  'and each clearing is in the audit trail with the moderator who did it'
);

-- ## What stays shut

set local role service_role;

select throws_ok(
  $$update public.asset_source_conflict set acknowledged_at = now()$$,
  '42501',
  null,
  'the secret key cannot clear an entry without a moderator behind it'
);

select * from finish();
rollback;
