-- A trusted account replacing somebody else's picture (issue #138).
--
-- The upload route checks can_replace_any_asset before it lets one account
-- replace a row another account uploaded, and the replacement takes the row
-- over: uploaded_by becomes the replacing account. The table refuses that
-- takeover for anybody who does not hold the capability, whatever the route
-- does, and the trail records every takeover with who did it and whose picture
-- it was.

begin;
select plan(15);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('a1111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('b2222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'holder@example.test'),
  ('c3333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'plain@example.test'),
  ('d4444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'closing@example.test');

select lives_ok(
  $$insert into public.user_capability (user_id, capability)
    values ('b2222222-2222-2222-2222-222222222222', 'can_replace_any_asset')$$,
  'can_replace_any_asset is a capability the table accepts'
);

select throws_ok(
  $$insert into public.user_capability (user_id, capability)
    values ('c3333333-3333-3333-3333-333333333333', 'can_replace_any_assets')$$,
  '23514',
  null,
  'a misspelt capability is still refused'
);

-- What the upload route writes, as the secret key with no session.
set local role service_role;
set local request.jwt.claims = '';

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-1380-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a.webp', 'bucket', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.1.sdz', 'approved', 'moderator', 'a1111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-1380-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b.webp', 'bucket', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.1.sdz', 'pending', null, 'a1111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-1380-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c.webp', 'bucket', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.1.sdz', 'approved', 'moderator', null),
  ('0f8fad5b-1380-4000-8000-00000000000d', 'bar', 'armpw', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'units/bar/buildpic/enc-d.webp', 'bucket', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.1.sdz', 'approved', 'moderator', 'a1111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-1380-4000-8000-00000000000e', 'bar', 'armck', 'buildpic', 'src-e', 'enc-e', 'webp-lossless-256', 'units/bar/buildpic/enc-e.webp', 'bucket', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.1.sdz', 'approved', 'moderator', 'd4444444-4444-4444-4444-444444444444');

-- A plain account taking over another account's approved picture. The route
-- refuses this first. The table refuses it as well.
select throws_ok(
  $$update public.asset
    set hash = 'enc-a-plain', source_hash = 'src-a-plain', path = 'units/bar/buildpic/enc-a-plain.webp',
        seen_at = now(), moderation = 'pending', approval_source = null,
        uploaded_by = 'c3333333-3333-3333-3333-333333333333'
    where id = '0f8fad5b-1380-4000-8000-00000000000a'$$,
  '42501',
  'replacing another account''s picture needs can_replace_any_asset',
  'an account without the capability cannot take over another account''s picture'
);

-- A plain account taking over a closed account's picture.
select throws_ok(
  $$update public.asset
    set hash = 'enc-c-plain', source_hash = 'src-c-plain', path = 'units/bar/buildpic/enc-c-plain.webp',
        seen_at = now(), moderation = 'pending', approval_source = null,
        uploaded_by = 'c3333333-3333-3333-3333-333333333333'
    where id = '0f8fad5b-1380-4000-8000-00000000000c'$$,
  '42501',
  'replacing another account''s picture needs can_replace_any_asset',
  'nor a picture whose uploader has closed their account'
);

-- The holder replacing the approved picture, the way writeUploadedAsset does.
select lives_ok(
  $$update public.asset
    set hash = 'enc-a2', source_hash = 'src-a2', path = 'units/bar/buildpic/enc-a2.webp',
        source_archive = 'bar_1.2.sdz', seen_at = now(), promoted_at = null,
        moderation = 'pending', approval_source = null,
        uploaded_by = 'b2222222-2222-2222-2222-222222222222'
    where id = '0f8fad5b-1380-4000-8000-00000000000a'$$,
  'the holder can replace another account''s picture'
);

-- The holder replacing a pending picture, which changes no moderation state.
update public.asset
set hash = 'enc-b2', source_hash = 'src-b2', path = 'units/bar/buildpic/enc-b2.webp',
    source_archive = 'bar_1.2.sdz', seen_at = now(),
    moderation = 'pending', approval_source = null,
    uploaded_by = 'b2222222-2222-2222-2222-222222222222'
where id = '0f8fad5b-1380-4000-8000-00000000000b';

-- The holder replacing a closed account's picture.
select lives_ok(
  $$update public.asset
    set hash = 'enc-c2', source_hash = 'src-c2', path = 'units/bar/buildpic/enc-c2.webp',
        source_archive = 'bar_1.2.sdz', seen_at = now(),
        moderation = 'pending', approval_source = null,
        uploaded_by = 'b2222222-2222-2222-2222-222222222222'
    where id = '0f8fad5b-1380-4000-8000-00000000000c'$$,
  'the holder can replace a picture whose uploader has closed their account'
);

-- The uploader replacing their own picture, which is not a takeover.
update public.asset
set hash = 'enc-d2', source_hash = 'src-d2', path = 'units/bar/buildpic/enc-d2.webp',
    source_archive = 'bar_1.2.sdz', seen_at = now(),
    moderation = 'pending', approval_source = null,
    uploaded_by = 'a1111111-1111-1111-1111-111111111111'
where id = '0f8fad5b-1380-4000-8000-00000000000d';

reset role;

select is(
  (select moderation || ' ' || uploaded_by from public.asset where id = '0f8fad5b-1380-4000-8000-00000000000a'),
  'pending b2222222-2222-2222-2222-222222222222',
  'the replaced picture is back in the queue and belongs to the holder'
);

select is(
  (select actor || ' ' || uploader from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000a' and action = 'replaced'),
  'b2222222-2222-2222-2222-222222222222 a1111111-1111-1111-1111-111111111111',
  'the trail records the takeover, with the holder as actor and the previous uploader as uploader'
);

select is(
  (select actor from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000a' and action = 'returned'),
  'b2222222-2222-2222-2222-222222222222'::uuid,
  'the return to the queue is put down to the holder, not to the previous uploader'
);

select is(
  (select array_agg(action order by id) from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000b'),
  array['replaced'],
  'a takeover of a pending picture is still recorded, though nothing the public sees changed'
);

select is(
  (select coalesce(uploader::text, 'nobody') from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000c' and action = 'replaced'),
  'nobody',
  'a takeover of a closed account''s picture is recorded with no previous uploader'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000d' and action = 'replaced'),
  0,
  'an uploader replacing their own picture is not a takeover'
);

-- Closing an account empties uploaded_by through the foreign key. That is not
-- a takeover either, and the table must not refuse it.
select lives_ok(
  $$delete from auth.users where id = 'd4444444-4444-4444-4444-444444444444'$$,
  'an account with pictures can still be closed'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-1380-4000-8000-00000000000e' and action = 'replaced'),
  0,
  'and closing it records no takeover'
);

-- The holder's quota now carries the pictures it took over, which is what the
-- upload route charges it for.
select is(
  public.account_asset_bytes('b2222222-2222-2222-2222-222222222222'),
  3 * 4096::bigint,
  'the pictures the holder took over count against the holder''s storage'
);

select * from finish();
rollback;
