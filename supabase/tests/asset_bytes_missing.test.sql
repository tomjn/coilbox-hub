-- Rows whose Blob bytes could not be read (issue #336).
--
-- `lib/assets/unreadable.test.ts` covers which rows get marked, and the have,
-- upload, resolve and promotion tests cover what a mark changes. What only the
-- database can prove is where a mark may sit, that promotion refuses a marked
-- row, and that replacing one queues its Blob path like any other replacement.

begin;
select plan(11);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by, updated_at)
values
  -- Approved in Blob long ago. The store will not return its bytes.
  ('0f8fad5b-6666-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111', now() - interval '3 days'),
  -- Approved in Blob long ago, and readable.
  ('0f8fad5b-6666-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b-Qm27Lx0v.webp', 'blob', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111', now() - interval '3 days'),
  -- Already on the durable tier.
  ('0f8fad5b-6666-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c.webp', 'static', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111', now() - interval '3 days');

select has_column('public', 'asset', 'bytes_missing_at', 'an asset row can say its bytes went missing');

-- ## Where a mark may sit

select lives_ok(
  $$update public.asset set bytes_missing_at = now() where id = '0f8fad5b-6666-4000-8000-00000000000a'$$,
  'a Blob row may be marked'
);

select throws_ok(
  $$update public.asset set bytes_missing_at = now() where id = '0f8fad5b-6666-4000-8000-00000000000c'$$,
  '23514', null,
  'a durable row may not, because its bytes are in a git history'
);

-- ## Promotion

select is(
  (select array_agg(id::text order by id) from public.promote_assets(
    ARRAY['0f8fad5b-6666-4000-8000-00000000000a', '0f8fad5b-6666-4000-8000-00000000000b']::uuid[],
    ARRAY['units/bar/buildpic/enc-a.webp', 'units/bar/buildpic/enc-b.webp']
  )),
  ARRAY['0f8fad5b-6666-4000-8000-00000000000b'],
  'promote_assets moves the readable row and not the marked one'
);

select is(
  (select tier || ' ' || path || ' ' || coalesce(blob_path, 'null') from public.asset where id = '0f8fad5b-6666-4000-8000-00000000000a'),
  'blob units/bar/buildpic/enc-a-Zx91Kp2w.webp null',
  'so the marked row keeps its tier and path and queues nothing'
);

-- ## The upload that replaces it

select throws_ok(
  $$update public.asset set tier = 'bucket', path = 'units/bar/buildpic/enc-a2.webp', hash = 'enc-a2' where id = '0f8fad5b-6666-4000-8000-00000000000a'$$,
  '23514', null,
  'a replacement that leaves the mark behind is refused, so it cannot hide a picture that is there'
);

select lives_ok(
  $$update public.asset set tier = 'bucket', path = 'units/bar/buildpic/enc-a2.webp', hash = 'enc-a2', bytes_missing_at = null, moderation = 'pending', approval_source = null where id = '0f8fad5b-6666-4000-8000-00000000000a'$$,
  'a replacement that clears the mark is accepted'
);

select is(
  (select reason from public.asset_orphan where path = 'units/bar/buildpic/enc-a-Zx91Kp2w.webp'),
  'superseded',
  'and the dead Blob path is queued for the Blob sweep like any superseded path'
);

select is(
  (select action from public.asset_event where asset_id = '0f8fad5b-6666-4000-8000-00000000000a' order by id desc limit 1),
  'returned',
  'and the trail says the picture went back to the queue'
);

-- ## Who may call promotion

select function_privs_are('public', 'promote_assets', ARRAY['uuid[]', 'text[]'], 'service_role', ARRAY['EXECUTE'],
  'the promotion job still calls promote_assets');
select function_privs_are('public', 'promote_assets', ARRAY['uuid[]', 'text[]'], 'authenticated', ARRAY[]::name[],
  'and a signed in account still cannot');

select * from finish();
rollback;
