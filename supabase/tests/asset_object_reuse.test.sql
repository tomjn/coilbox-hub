-- Two rows pointing at one staging object (issue #132).
--
-- Two halves, and they are the two ends of the same rule. One is which objects
-- the upload route may hand to a second row, which is public.reusable_staging_
-- object. The other is what the orphan trigger does when a row that shares an
-- object moves off it, which is nothing, because the object is not spare.
--
-- Everything about the sweep and the drain is in lib/assets/orphan.test.ts and
-- lib/assets/promote.test.ts, which can kill a run mid-way. What can only be
-- proved here is the half that happens inside the statement.

begin;
select plan(12);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

-- Five pictures, in two shared pairs. The first pair is the same bytes under
-- two identities, which is what #132 produces and what everything below turns
-- on. The third is already promoted. The last pair shares an object that
-- promotion is about to take away.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-3333-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'shared', 'webp-lossless-256', 'units/bar/buildpic/shared-Hn4vQ2rT.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-3333-4000-8000-00000000000b', 'bar', 'armadvsol', 'buildpic', 'src-b', 'shared', 'webp-lossless-256', 'units/bar/buildpic/shared-Hn4vQ2rT.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'pending', null, '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-3333-4000-8000-00000000000c', 'bar', 'armllt', 'buildpic', 'src-c', 'moved', 'webp-lossless-256', 'units/bar/buildpic/moved.webp', 'static', 'uploaded', 'image/webp', 2048, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-3333-4000-8000-00000000000d', 'bar', 'armcom', 'buildpic', 'src-d', 'draining', 'webp-lossless-256', 'units/bar/buildpic/draining-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-3333-4000-8000-00000000000e', 'bar', 'armfus', 'buildpic', 'src-e', 'draining', 'webp-lossless-256', 'units/bar/buildpic/draining-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 1024, 128, 128, 'bar_1.2.sdz', 'pending', null, '11111111-1111-1111-1111-111111111111');

-- ## Which objects an upload may take instead of writing its own

select is(
  public.reusable_staging_object('never-seen'),
  null,
  'bytes the store has never held are bytes the upload has to write'
);

select is(
  public.reusable_staging_object('shared'),
  'units/bar/buildpic/shared-Hn4vQ2rT.webp',
  'and bytes it is already holding come back as the object to point at'
);

select is(
  public.reusable_staging_object('moved'),
  null,
  'the durable tier is not offered: those bytes are in a git history, not the store'
);

-- Promotion has finished with this object and is waiting on the durable tier
-- before deleting it, which it will.
update public.asset
set path = 'units/bar/buildpic/draining.webp',
    tier = 'static',
    promoted_at = now(),
    blob_path = 'units/bar/buildpic/draining-Zx91Kp2w.webp'
where id = '0f8fad5b-3333-4000-8000-00000000000d';

select is(
  public.reusable_staging_object('draining'),
  null,
  'nor an object promotion has queued for deletion, even though a row is still serving it'
);

-- The sweep's queue, written by hand. The trigger below will no longer produce
-- this state, so the only way to reach it is the narrow race the migration
-- names: an upload reads a path and the row naming it is replaced before the
-- new row lands. The exclusion is what stops the next upload inheriting it.
insert into public.asset_orphan (path, bytes, reason)
values ('units/bar/buildpic/shared-Hn4vQ2rT.webp', 4096, 'superseded');

select is(
  public.reusable_staging_object('shared'),
  null,
  'nor one the sweep has heard of, whether it has deleted it yet or not'
);

delete from public.asset_orphan where path = 'units/bar/buildpic/shared-Hn4vQ2rT.webp';

select is(
  public.reusable_staging_object('shared'),
  'units/bar/buildpic/shared-Hn4vQ2rT.webp',
  'and with the queue clear it is on offer again'
);

-- ## What the trigger does when a shared object stops being one row's
--
-- The condition #132 adds. Before it, the row losing a path was evidence enough
-- that the object was spare, and it is not: the other row is still serving the
-- picture from it.

update public.asset
set path = 'units/bar/buildpic/newer-Lm5nB7vC.webp',
    hash = 'newer',
    source_hash = 'src-a2',
    bytes = 5000,
    moderation = 'pending',
    approval_source = null,
    seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000a';

select is(
  (select count(*) from public.asset_orphan
    where path = 'units/bar/buildpic/shared-Hn4vQ2rT.webp')::int,
  0,
  'a newer archive for one of two rows sharing an object queues no deletion'
);

select is(
  (select path from public.asset where id = '0f8fad5b-3333-4000-8000-00000000000b'),
  'units/bar/buildpic/shared-Hn4vQ2rT.webp',
  'because the other row is still serving the picture out of it'
);

-- And now the last row naming it moves off, which is the ordinary case the
-- trigger has always been for.
update public.asset
set path = 'units/bar/buildpic/newest-Qw3eR9tY.webp',
    hash = 'newest',
    source_hash = 'src-b2',
    seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000b';

select is(
  (select count(*) from public.asset_orphan
    where path = 'units/bar/buildpic/shared-Hn4vQ2rT.webp')::int,
  1,
  'and the last row to let go of it is the one that queues it'
);

select is(
  (select bytes from public.asset_orphan
    where path = 'units/bar/buildpic/shared-Hn4vQ2rT.webp'),
  4096,
  'with the length the object has, which is what the storage meter counts'
);

-- The other kind of claim. The row that put this object there has been promoted
-- and holds the pathname in `blob_path`, so it will delete it once nothing is
-- serving it. The row now letting go of it must not queue it as well, or two
-- jobs are deleting one object and the storage meter counts it twice.
update public.asset
set path = 'units/bar/buildpic/replaced-Rt8yU3iO.webp',
    hash = 'replaced',
    source_hash = 'src-e2',
    seen_at = now()
where id = '0f8fad5b-3333-4000-8000-00000000000e';

select is(
  (select count(*) from public.asset_orphan
    where path = 'units/bar/buildpic/draining-Zx91Kp2w.webp')::int,
  0,
  'an object promotion is already holding in blob_path is not queued a second time'
);

-- ## Who may ask

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.reusable_staging_object('shared')$$,
  '42501',
  null,
  'a browser cannot ask where unreviewed bytes are already sitting'
);

select * from finish();
rollback;
