-- Releasing the bucket objects of rejected pictures (issue #348).
--
-- Proves which rejected rows stop claiming their object, that the row and its
-- audit trail are left alone, and that `return_asset` refuses the rows whose
-- object the sweep is free to delete.

begin;
select plan(15);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('33333333-3333-3333-3333-333333333333', 'can_moderate');

-- Rejected rows are inserted with their age, because a write to the row would
-- touch `updated_at`.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, rejection_kind, uploaded_by, updated_at)
values
  ('0f8fad5b-6666-4000-8000-00000000000a', 'bar', 'oldeditorial', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/old-editorial.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', 'editorial', '11111111-1111-1111-1111-111111111111', now() - interval '15 days'),
  ('0f8fad5b-6666-4000-8000-00000000000b', 'bar', 'neweditorial', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/new-editorial.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', 'editorial', '11111111-1111-1111-1111-111111111111', now() - interval '13 days'),
  ('0f8fad5b-6666-4000-8000-00000000000c', 'bar', 'oldsafety', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/old-safety.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', 'safety', '11111111-1111-1111-1111-111111111111', now() - interval '400 days'),
  ('0f8fad5b-6666-4000-8000-00000000000d', 'bar', 'oldunrecorded', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'units/bar/buildpic/old-unrecorded.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', 'unrecorded', '11111111-1111-1111-1111-111111111111', now() - interval '400 days'),
  ('0f8fad5b-6666-4000-8000-00000000000e', 'bar', 'oldpending', 'buildpic', 'src-e', 'enc-e', 'webp-lossless-256', 'units/bar/buildpic/old-pending.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'pending', null, '11111111-1111-1111-1111-111111111111', now() - interval '400 days');

insert into storage.objects (bucket_id, name, metadata)
values
  ('staged-pictures', 'units/bar/buildpic/old-editorial.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/new-editorial.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/old-safety.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/old-unrecorded.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/old-pending.webp', '{"size": 4096}');

reset role;
set local role service_role;
set local request.jwt.claims = '';

-- ## What the sweep lists

select set_eq(
  $$select object_path from public.unclaimed_staged_objects(10)$$,
  ARRAY['units/bar/buildpic/old-editorial.webp'],
  'only an editorial rejection older than the grace period stops claiming its object'
);

select set_eq(
  $$select object_path from public.reserve_staged_deletions(
      ARRAY['units/bar/buildpic/old-editorial.webp', 'units/bar/buildpic/new-editorial.webp', 'units/bar/buildpic/old-safety.webp', 'units/bar/buildpic/old-unrecorded.webp', 'units/bar/buildpic/old-pending.webp'],
      true)$$,
  ARRAY['units/bar/buildpic/old-editorial.webp'],
  'and a reservation agrees with the listing'
);

select is(
  (select count(*)::int from public.asset where id = '0f8fad5b-6666-4000-8000-00000000000a'
     and moderation = 'rejected' and rejection_kind = 'editorial'
     and path = 'units/bar/buildpic/old-editorial.webp' and tier = 'bucket'),
  1,
  'the released row keeps its state, kind, path and tier'
);

select is(
  (select count(*)::int from public.asset_event where asset_id = '0f8fad5b-6666-4000-8000-00000000000a' and action = 'rejected' and rejection_kind = 'editorial'),
  1,
  'and its audit trail'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armadvsol', 'buildpic', 'src-f', 'enc-f', 'webp-lossless-256', 'units/bar/buildpic/old-editorial.webp', 'bucket', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz')$$,
  '55006', null,
  'a reserved object still cannot be claimed by a new row'
);

select is(
  public.release_staged_deletions(ARRAY['units/bar/buildpic/old-editorial.webp']),
  1,
  'the sweep releases its reservation once the object is deleted'
);

-- ## Returning a picture to the queue

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.return_asset('0f8fad5b-6666-4000-8000-00000000000a'),
  false,
  'a moderator cannot return an editorial rejection whose object may be gone'
);

select is(
  public.return_asset('0f8fad5b-6666-4000-8000-00000000000b'),
  true,
  'but can return one still inside the grace period'
);

select is(
  public.return_asset('0f8fad5b-6666-4000-8000-00000000000c'),
  false,
  'and a safety rejection stays refused as before'
);

reset role;
set local role service_role;
set local request.jwt.claims = '';

select is(
  (select moderation from public.asset where id = '0f8fad5b-6666-4000-8000-00000000000a'),
  'rejected',
  'the refused row is still rejected'
);

select set_eq(
  $$select object_path from public.unclaimed_staged_objects(10)$$,
  ARRAY['units/bar/buildpic/old-editorial.webp'],
  'a returned row claims its object again, so only the released one is listed'
);

-- ## A rejection made now is held for the full grace period

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.reject_asset('0f8fad5b-6666-4000-8000-00000000000e', 'editorial'),
  true,
  'a moderator rejects a long pending picture as editorial'
);

reset role;
set local role service_role;
set local request.jwt.claims = '';

select ok(
  'units/bar/buildpic/old-pending.webp' not in (select object_path from public.unclaimed_staged_objects(10)),
  'its object is still claimed, because the age counts from the rejection'
);

select is(
  (select count(*)::int from public.asset_event where asset_id = '0f8fad5b-6666-4000-8000-00000000000e' and action = 'rejected' and actor = '33333333-3333-3333-3333-333333333333'),
  1,
  'and the rejection is recorded against the moderator'
);

select function_privs_are('public', 'asset_holds_staged_bytes', ARRAY['text', 'text', 'timestamp with time zone'], 'authenticated', ARRAY[]::name[],
  'nobody signed in can call the claim check directly');

select * from finish();
rollback;
