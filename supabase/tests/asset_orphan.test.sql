-- Keeping hold of the name of a staging object that has stopped being named
-- (issue #113).
--
-- Everything about the sweep itself is in `lib/assets/orphan.test.ts`, which can
-- kill a run between the delete and the settle. What can only be proved here is
-- the half that has to happen inside the statement that causes it: a newer
-- archive overwrites `path`, and the object that path named is unreachable from
-- that instant unless something copied the name out first. `list()` is banned,
-- so a name Postgres forgets is a name nobody can recover.
--
-- The interesting half is what is not recorded. Promotion overwrites a staging
-- path too, and keeps the old one in `blob_path` itself, so recording it here
-- would queue one object for deletion in two places.

begin;
select plan(20);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

-- Three pictures. One is about to be replaced, one is about to be promoted, and
-- one is there so the sweep has something to leave alone.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-2222-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'units/bar/buildpic/enc-a-Hn4vQ2rT.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-2222-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'units/bar/buildpic/enc-b-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 8192, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-2222-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'units/bar/buildpic/enc-c-Qw3eR9tY.webp', 'blob', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111');

select is(
  (select count(*) from public.asset_orphan)::int, 0,
  'storing a picture claims an object rather than orphaning one'
);

-- ## A newer archive replacing the bytes (#106)
--
-- What `writePendingAsset` does: the row is updated in place, back to pending,
-- with the pathname of a fresh object. The old pathname is gone from the row in
-- the same statement.

update public.asset
set path = 'units/bar/buildpic/enc-a2-Lm5nB7vC.webp',
    hash = 'enc-a2',
    source_hash = 'src-a2',
    bytes = 5000,
    moderation = 'pending',
    approval_source = null,
    seen_at = now()
where id = '0f8fad5b-2222-4000-8000-00000000000a';

select is(
  (select path from public.asset_orphan where reason = 'superseded'),
  'units/bar/buildpic/enc-a-Hn4vQ2rT.webp',
  'the object the row named a moment ago is written down before the name is lost'
);

select is(
  (select bytes from public.asset_orphan where reason = 'superseded'),
  4096,
  'with the length it had, not the replacement''s, so the storage meter is right'
);

select is(
  (select deleted_at from public.asset_orphan where reason = 'superseded'),
  null,
  'outstanding, which is the only state a new one has'
);

select is(
  (select path from public.asset where id = '0f8fad5b-2222-4000-8000-00000000000a'),
  'units/bar/buildpic/enc-a2-Lm5nB7vC.webp',
  'and the row itself points at the replacement, untouched by any of this'
);

-- ## What promotion does, which is not this

reset role;
set local role service_role;
set local request.jwt.claims = '';

select is(
  (select count(*)::int from public.promote_assets(
    ARRAY['0f8fad5b-2222-4000-8000-00000000000b'::uuid],
    ARRAY['units/bar/buildpic/enc-b.webp'])),
  1,
  'a picture is promoted, which overwrites a staging path just like a replacement does'
);

select is(
  (select count(*) from public.asset_orphan
    where path = 'units/bar/buildpic/enc-b-Zx91Kp2w.webp')::int,
  0,
  'and nothing is queued here, because blob_path already names the object'
);

select is(
  (select blob_path from public.asset where id = '0f8fad5b-2222-4000-8000-00000000000b'),
  'units/bar/buildpic/enc-b-Zx91Kp2w.webp',
  'which is where the drain at the top of the next promotion run will find it'
);

-- A promoted row whose bytes a newer archive then replaces. `path` is a durable
-- path by now, and a durable path is bytes in a public git history that nothing
-- here may delete.
update public.asset
set path = 'units/bar/buildpic/enc-b2-Rt8yU3iO.webp',
    hash = 'enc-b2',
    source_hash = 'src-b2',
    tier = 'blob',
    promoted_at = null,
    moderation = 'pending',
    approval_source = null
where id = '0f8fad5b-2222-4000-8000-00000000000b';

select is(
  (select count(*) from public.asset_orphan
    where path = 'units/bar/buildpic/enc-b.webp')::int,
  0,
  'overwriting a durable path queues no deletion: the durable tier is a takedown, not a sweep'
);

select is(
  (select blob_path from public.asset where id = '0f8fad5b-2222-4000-8000-00000000000b'),
  'units/bar/buildpic/enc-b-Zx91Kp2w.webp',
  'and the object promotion is still holding is still held'
);

-- ## Updates that orphan nothing

update public.asset set moderation = 'approved', approval_source = 'moderator'
where id = '0f8fad5b-2222-4000-8000-00000000000c';

select is(
  (select count(*) from public.asset_orphan)::int,
  1,
  'approving a picture moves no bytes, so it queues nothing'
);

-- ## An object stored for a row that was never written

select is(
  public.record_unclaimed_object('units/bar/buildpic/enc-x-Nothing1.webp', 3000),
  true,
  'the upload route says so when its own delete failed as well'
);

select is(
  public.record_unclaimed_object('units/bar/buildpic/enc-x-Nothing1.webp', 3000),
  false,
  'and saying it twice is one object, not two'
);

-- ## What the meters read

select set_eq(
  $$select tier || ' ' || variant || ' ' || objects || ' ' || bytes
    from public.asset_storage_usage()$$,
  ARRAY[
    'blob buildpic 3 15240',
    'orphan superseded 1 4096',
    'orphan unclaimed 1 3000'
  ],
  'usage is grouped by tier and class, with everything still to be swept counted too'
);

-- ## Settling, once the objects are actually gone

select is(
  public.clear_asset_orphans(ARRAY[(select id from public.asset_orphan where reason = 'superseded')]),
  1,
  'a swept object is settled by the sweep that deleted it'
);

select is(
  public.clear_asset_orphans(ARRAY[(select id from public.asset_orphan where reason = 'superseded')]),
  0,
  'and settling it twice settles nothing, which is what makes the sweep safe to repeat'
);

select is(
  (select count(*) from public.asset_orphan where reason = 'unclaimed' and deleted_at is null)::int,
  1,
  'the entry nobody swept is still outstanding'
);

-- ## Who may write it

select throws_ok(
  $$insert into public.asset_orphan (path, bytes, reason)
    values ('units/bar/buildpic/made-up.webp', 10, 'superseded')$$,
  '42501',
  null,
  'nothing holding the secret key can queue a deletion by hand'
);

select throws_ok(
  $$update public.asset_orphan set deleted_at = null$$,
  '42501',
  null,
  'nor put a settled object back, which would delete something that is already gone'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.asset_storage_usage()$$,
  '42501',
  null,
  'and a browser cannot read what the stores hold, because a path is a public URL'
);

select * from finish();
rollback;
