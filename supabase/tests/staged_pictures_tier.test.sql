-- Rows on the Supabase bucket tier (issue #332), and the stores that are no
-- longer allowed since Vercel Blob was removed (#338). Promotion moves bucket
-- rows since #335, and staged_pictures_promotion.test.sql covers the rest.

begin;
select plan(13);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by, updated_at)
values
  -- Approved long ago on the bucket, which is everything promotion looks for
  -- apart from the tier.
  ('0f8fad5b-4444-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111', now() - interval '3 days');

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

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armpw', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'units/bar/buildpic/enc-d.webp', 'blob', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz')$$,
  '23514',
  null,
  'including Vercel Blob, which is gone'
);

select is(
  (select column_default from information_schema.columns
   where table_schema = 'public' and table_name = 'asset' and column_name = 'tier'),
  '''bucket''::text',
  'a row inserted without a tier says it is in the bucket'
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
  'and queues its path for deletion from the bucket'
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
  $$update public.game set logo_staged_tier = 'bucket', banner_staged_tier = 'bucket'
    where id = '0f8fad5b-4444-4000-8000-000000000001'$$,
  'a game row may say its staged logo and banner are in the bucket'
);

select throws_ok(
  $$update public.game set logo_staged_tier = 'blob' where id = '0f8fad5b-4444-4000-8000-000000000001'$$,
  '23514',
  null,
  'but not in Vercel Blob'
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
