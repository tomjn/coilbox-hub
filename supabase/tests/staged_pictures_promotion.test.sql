-- Promoting and sweeping pictures out of the Supabase bucket (issue #335).
--
-- `lib/assets/promote.test.ts` and `lib/assets/orphan.test.ts` prove the order
-- the jobs delete in. What only the database can prove is which objects count
-- as claimed, that a reservation and a claim on one path cannot both stand, and
-- who may call any of it.

begin;
select plan(31);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, rejection_kind, uploaded_by)
values
  -- Approved in the bucket, due to move.
  ('0f8fad5b-5555-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', null, '11111111-1111-1111-1111-111111111111'),
  -- Approved in Blob, due to move.
  ('0f8fad5b-5555-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', null, '11111111-1111-1111-1111-111111111111'),
  -- Rejected in the bucket. Its object is still named.
  ('0f8fad5b-5555-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', null, 'editorial', '11111111-1111-1111-1111-111111111111'),
  -- Imported straight to the durable tier at a path the bucket also holds.
  ('0f8fad5b-5555-4000-8000-00000000000d', 'bar', 'armpw', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'units/bar/buildpic/enc-d.webp', 'static', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', null, '11111111-1111-1111-1111-111111111111');

insert into public.game (id, shortname, logo_path, logo_hash, logo_staged_tier, banner_path, banner_hash, banner_staged_tier)
values ('0f8fad5b-5555-4000-8000-000000000001', 'BA', 'games/BA/logo.webp', 'logo-hash', 'bucket', 'games/BA/banner.webp', 'banner-hash', null);

insert into storage.objects (bucket_id, name, metadata)
values
  ('staged-pictures', 'units/bar/buildpic/enc-a.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/enc-c.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/enc-d.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/spare.webp', '{"size": 2048}'),
  ('staged-pictures', 'games/BA/logo.webp', '{"size": 1024}'),
  ('staged-pictures', 'games/BA/banner.webp', '{"size": 1024}');

-- ## Who may call it

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select * from public.unclaimed_staged_objects(10)$$,
  '42501', null,
  'a signed in account cannot list the bucket'
);
select throws_ok(
  $$select * from public.reserve_staged_deletions(ARRAY['units/bar/buildpic/spare.webp'], true)$$,
  '42501', null,
  'nor reserve a deletion'
);
select throws_ok(
  $$select public.release_staged_deletions(ARRAY['units/bar/buildpic/spare.webp'])$$,
  '42501', null,
  'nor release one'
);

reset role;
set local role anon;
set local request.jwt.claims = '';

select throws_ok(
  $$select * from public.unclaimed_staged_objects(10)$$,
  '42501', null,
  'and neither can anybody holding the publishable key'
);
select throws_ok(
  $$select * from public.reserve_staged_deletions(ARRAY['units/bar/buildpic/spare.webp'], true)$$,
  '42501', null,
  'including reserving a deletion'
);

select function_privs_are('public', 'lock_staged_path', ARRAY['text'], 'service_role', ARRAY[]::name[],
  'the lock is taken inside the functions and nobody calls it directly');
select function_privs_are('public', 'refuse_reserved_staged_paths', ARRAY['text[]'], 'service_role', ARRAY[]::name[],
  'nor the refusal the triggers share');

-- ## What the sweep lists

reset role;
set local role service_role;
set local request.jwt.claims = '';

select set_eq(
  $$select object_path from public.unclaimed_staged_objects(10)$$,
  ARRAY['units/bar/buildpic/enc-d.webp', 'units/bar/buildpic/spare.webp', 'games/BA/banner.webp'],
  'only objects no staging row, queued path or staged game picture names are listed'
);

select is(
  (select bytes from public.unclaimed_staged_objects(10) where object_path = 'units/bar/buildpic/spare.webp'),
  2048::bigint,
  'with the size storage recorded'
);

select is(
  (select count(*)::int from public.unclaimed_staged_objects(1)),
  1,
  'and no more than the caller asked for'
);

-- ## Promotion moves a bucket row and queues its bucket object

select set_eq(
  $$select id::text || ' ' || blob_path || ' ' || blob_path_tier from public.promote_assets(
      ARRAY['0f8fad5b-5555-4000-8000-00000000000a'::uuid, '0f8fad5b-5555-4000-8000-00000000000b'::uuid, '0f8fad5b-5555-4000-8000-00000000000c'::uuid],
      ARRAY['units/bar/buildpic/enc-a.webp', 'units/bar/buildpic/enc-b.webp', 'units/bar/buildpic/enc-c.webp'])$$,
  ARRAY[
    '0f8fad5b-5555-4000-8000-00000000000a units/bar/buildpic/enc-a.webp bucket',
    '0f8fad5b-5555-4000-8000-00000000000b units/bar/buildpic/enc-b-Zx91Kp2w.webp blob'
  ],
  'approved rows in either store move, and each comes back with the store its staging path is in'
);

select is(
  (select tier || ' ' || path || ' ' || blob_path_tier from public.asset where id = '0f8fad5b-5555-4000-8000-00000000000a'),
  'static units/bar/buildpic/enc-a.webp bucket',
  'a bucket row keeps its path, because the durable path is the same one'
);

select ok(
  'units/bar/buildpic/enc-a.webp' not in (select object_path from public.unclaimed_staged_objects(10)),
  'and its bucket object is still claimed, by the queue, until the drain releases it'
);

select throws_ok(
  $$update public.asset set blob_path = null where id = '0f8fad5b-5555-4000-8000-00000000000a'$$,
  '23514', null,
  'a store cannot be recorded for a path that is not queued'
);

-- ## Reserving

select set_eq(
  $$select object_path from public.reserve_staged_deletions(
      ARRAY['units/bar/buildpic/enc-a.webp', 'units/bar/buildpic/enc-c.webp', 'games/BA/logo.webp', 'units/bar/buildpic/spare.webp'],
      true)$$,
  ARRAY['units/bar/buildpic/spare.webp'],
  'a sweep''s reservation turns down a queued path, a rejected row''s path and a staged game picture'
);

select set_eq(
  $$select object_path from public.reserve_staged_deletions(
      ARRAY['units/bar/buildpic/enc-a.webp', 'units/bar/buildpic/enc-c.webp'],
      false)$$,
  ARRAY['units/bar/buildpic/enc-a.webp'],
  'the drain may reserve a queued path, and still not a path a staging row names'
);

select set_eq(
  $$select object_path from public.reserve_staged_deletions(ARRAY['units/bar/buildpic/spare.webp', 'units/bar/buildpic/spare.webp'], true)$$,
  ARRAY['units/bar/buildpic/spare.webp'],
  'a path already reserved comes back again, once, so a later run can finish the delete'
);

select is(
  (select count(*)::int from public.staged_object_deletion),
  2,
  'and is still one reservation'
);

-- ## A reserved path cannot be claimed

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armadvsol', 'buildpic', 'src-e', 'spare', 'webp-lossless-256', 'units/bar/buildpic/spare.webp', 'bucket', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz')$$,
  '55006', null,
  'an upload reusing an object that is being deleted is refused'
);

select throws_ok(
  $$update public.asset set path = 'units/bar/buildpic/spare.webp', hash = 'spare'
    where id = '0f8fad5b-5555-4000-8000-00000000000c'$$,
  '55006', null,
  'and so is a replacement that would point at it'
);

select is(
  (select path from public.asset where id = '0f8fad5b-5555-4000-8000-00000000000c'),
  'units/bar/buildpic/enc-c.webp',
  'which leaves the row as it was'
);

select lives_ok(
  $$update public.asset set bytes = 4097 where id = '0f8fad5b-5555-4000-8000-00000000000c'$$,
  'a write that claims no new path is not held up'
);

select throws_ok(
  $$update public.game set banner_path = 'units/bar/buildpic/spare.webp', banner_staged_tier = 'bucket'
    where id = '0f8fad5b-5555-4000-8000-000000000001'$$,
  '55006', null,
  'a game picture cannot claim it either'
);

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armfus', 'buildpic', 'src-f', 'spare-blob', 'webp-lossless-256', 'units/bar/buildpic/spare.webp', 'blob', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz')$$,
  'a Blob row at the same name is another store and is not refused'
);

select lives_ok(
  $$update public.game set logo_staged_tier = null where id = '0f8fad5b-5555-4000-8000-000000000001'$$,
  'clearing a staged game picture is not a claim'
);

-- ## Releasing

select is(
  public.release_staged_deletions(ARRAY['units/bar/buildpic/spare.webp', 'units/bar/buildpic/nothing.webp']),
  1,
  'releasing answers with how many reservations there were'
);

select is(
  public.release_staged_deletions(ARRAY['units/bar/buildpic/spare.webp']),
  0,
  'and doing it twice is not an error'
);

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armadvsol', 'buildpic', 'src-e', 'spare', 'webp-lossless-256', 'units/bar/buildpic/spare.webp', 'bucket', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz')$$,
  'once released, the same upload is accepted'
);

select set_eq(
  $$select object_path from public.reserve_staged_deletions(ARRAY['units/bar/buildpic/spare.webp', 'games/BA/logo.webp'], true)$$,
  ARRAY['games/BA/logo.webp'],
  'and the object is claimed again, while the cleared game picture is not'
);

-- ## Clearing the queue clears the store with it

select is(
  public.clear_promoted_blob_paths(ARRAY['0f8fad5b-5555-4000-8000-00000000000a'::uuid]),
  1,
  'a cleared queue entry is counted'
);

select is(
  (select coalesce(blob_path, 'none') || ' ' || coalesce(blob_path_tier, 'none') from public.asset where id = '0f8fad5b-5555-4000-8000-00000000000a'),
  'none none',
  'and takes its store with it'
);

select * from finish();
rollback;
