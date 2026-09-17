-- An upload that skips the moderation queue still leaves a trail (issue #115).
--
-- A moderator's own uploads, and uploads from an account holding
-- can_publish_unreviewed, are written approved with approval_source 'bypass'.
-- The trail has to show each time that put bytes in front of the public,
-- including a replacement that goes from approved to approved and so changes no
-- moderation state at all.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

-- What the upload route writes for an uploader who skips the queue, as the
-- secret key with no session.
set local role service_role;
set local request.jwt.claims = '';

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-3333-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a-Hn4vQ2rT.webp', 'bucket', 'rendered', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'bypass', '22222222-2222-2222-2222-222222222222'),
  ('0f8fad5b-3333-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b-Zx91Kp2w.webp', 'bucket', 'rendered', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'pending', null, '22222222-2222-2222-2222-222222222222');

reset role;

select is(
  (select action || ' ' || actor from public.asset_event where asset_id = '0f8fad5b-3333-4000-8000-00000000000a'),
  'bypassed 22222222-2222-2222-2222-222222222222',
  'an upload approved at write time is recorded as bypassed, with the uploader as the actor'
);

set local role service_role;

-- A newer archive from the same uploader, still skipping the queue.
update public.asset
set path = 'units/bar/buildpic/enc-a2-Lm5nB7vC.webp', hash = 'enc-a2', source_hash = 'src-a2', seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000a';

-- An ordinary pending upload replaced by a newer pending one.
update public.asset
set path = 'units/bar/buildpic/enc-b2-Qw3eR9tY.webp', hash = 'enc-b2', source_hash = 'src-b2', seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000b';

-- A change to an approved row that is not new bytes.
update public.asset set seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000a';

reset role;

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-3333-4000-8000-00000000000a' and action = 'bypassed'),
  2,
  'new bytes on a row that stays approved are a second bypass, not nothing'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-3333-4000-8000-00000000000b'),
  0,
  'new bytes on a pending row put nothing in front of anybody, so nothing is recorded'
);

set local role service_role;

-- A moderator approving the pending one in the grid, which is a different
-- authority from a bypass and has to read as one.
update public.asset set moderation = 'approved', approval_source = 'moderator'
where id = '0f8fad5b-3333-4000-8000-00000000000b';

reset role;

select is(
  (select action from public.asset_event where asset_id = '0f8fad5b-3333-4000-8000-00000000000b'),
  'approved',
  'an approval out of the queue is still recorded as approved'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id in ('0f8fad5b-3333-4000-8000-00000000000a', '0f8fad5b-3333-4000-8000-00000000000b')),
  3,
  'and nothing else was recorded along the way'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
    values ('bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c.webp', 'bucket', 'rendered', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', null, '22222222-2222-2222-2222-222222222222')$$,
  '23514',
  null,
  'an approved upload has to say what approved it'
);

select * from finish();
rollback;
