-- Rows on the Supabase bucket tier, and what the Blob era functions do with
-- them (issue #332).
--
-- The orphan queue only knows Blob. What can be proved here is that replacing a
-- `bucket` row never queues its path for the Blob sweep. Promotion moves bucket
-- rows since #335, and staged_pictures_promotion.test.sql covers the rest.

begin;
select plan(12);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by, updated_at)
values
  -- Approved long ago on the bucket, which is everything promotion looks for
  -- apart from the tier.
  ('0f8fad5b-4444-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111', now() - interval '3 days'),
  -- Still in Blob, and about to be replaced by an upload to the bucket.
  ('0f8fad5b-4444-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz', 'pending', null, '11111111-1111-1111-1111-111111111111', now());

-- ## The tier values

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c.webp', 'bucket', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz')$$,
  'an asset row may say its bytes are in the bucket'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armpw', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'units/bar/buildpic/enc-d.webp', 'supabase', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz')$$,
  '23514',
  null,
  'and nowhere the hub does not know about'
);

-- ## Promotion moves a bucket row (#335)

select is(
  (select count(*) from public.promote_assets(
    ARRAY['0f8fad5b-4444-4000-8000-00000000000a'::uuid],
    ARRAY['units/bar/buildpic/enc-a.webp']
  ))::int,
  1,
  'promote_assets moves a row on the bucket tier'
);

select is(
  (select tier || ' ' || path || ' ' || blob_path || ' ' || blob_path_tier from public.asset where id = '0f8fad5b-4444-4000-8000-00000000000a'),
  'static units/bar/buildpic/enc-a.webp units/bar/buildpic/enc-a.webp bucket',
  'and queues its path for deletion from the bucket, not from Blob'
);

-- ## Replacements and the Blob sweep's queue

update public.asset
set tier = 'bucket', path = 'units/bar/buildpic/enc-b2.webp', hash = 'enc-b2', source_hash = 'src-b2'
where id = '0f8fad5b-4444-4000-8000-00000000000b';

select is(
  (select reason from public.asset_orphan where path = 'units/bar/buildpic/enc-b-Zx91Kp2w.webp'),
  'superseded',
  'a Blob row replaced by a bucket upload queues its old Blob object'
);

update public.asset
set path = 'units/bar/buildpic/enc-b3.webp', hash = 'enc-b3', source_hash = 'src-b3'
where id = '0f8fad5b-4444-4000-8000-00000000000b';

select is(
  (select count(*) from public.asset_orphan where path = 'units/bar/buildpic/enc-b2.webp')::int,
  0,
  'a bucket row replaced again queues nothing, because the queue is swept out of Blob'
);

-- ## Game rows

insert into public.game (id, shortname, logo_path, logo_hash)
values ('0f8fad5b-4444-4000-8000-000000000001', 'BA', 'games/BA/logo.webp', 'logo-hash');

select is(
  (select logo_staged_tier from public.game where id = '0f8fad5b-4444-4000-8000-000000000001'),
  null,
  'a game row records no staged copy unless a writer says where it is'
);

select lives_ok(
  $$update public.game set logo_staged_tier = 'bucket', banner_staged_tier = 'blob'
    where id = '0f8fad5b-4444-4000-8000-000000000001'$$,
  'a game row may say its staged logo is in the bucket and its banner in Blob'
);

select throws_ok(
  $$update public.game set logo_staged_tier = 'static' where id = '0f8fad5b-4444-4000-8000-000000000001'$$,
  '23514',
  null,
  'the durable tier is not a staging store'
);

select throws_ok(
  $$update public.game set banner_staged_tier = 'elsewhere' where id = '0f8fad5b-4444-4000-8000-000000000001'$$,
  '23514',
  null,
  'nor is anywhere else'
);

-- An owner edits the columns the ownership grant names and nothing else, so a
-- browser cannot point the promotion job at a different store.
select ok(
  not has_column_privilege('authenticated', 'public.game', 'logo_staged_tier', 'UPDATE'),
  'a signed in account cannot write logo_staged_tier'
);

select ok(
  not has_column_privilege('authenticated', 'public.game', 'banner_staged_tier', 'UPDATE'),
  'nor banner_staged_tier'
);

select * from finish();
rollback;
