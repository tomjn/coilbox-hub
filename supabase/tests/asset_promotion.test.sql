-- Moving rows to the durable tier, and the three ways a row can be turned down
-- in the middle of a batch (issue #111).
--
-- The job's guarantee is about order: the object is committed to the assets
-- repo, then the row moves, then the staging copy is deleted, and an
-- interrupted run leaves the picture in both tiers rather than in neither.
-- `lib/assets/promote.test.ts` kills a run at each of those steps. What can
-- only be proved here is the part the database is responsible for.
--
-- One statement is one transaction, so a batch either moves or does not, and
-- the caller's delete list is exactly the rows that moved. That matters
-- because deleting the staging object of a row that did not move is the one
-- failure the whole design exists to prevent, and the caller has no other way
-- to find out which rows the statement turned down.

begin;
select plan(22);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test');

-- Four rows, one per case. The staging paths carry the suffix Blob adds, which
-- is the thing promotion has to keep hold of rather than overwrite.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, tier, origin, mime, bytes, width, height, source_archive, moderation, approval_source, rejection_kind, uploaded_by)
values
  -- Due to move.
  ('0f8fad5b-1111-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'buildpic-lossless', 'units/bar/buildpic/enc-a-Hn4vQ2rT.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', null, '11111111-1111-1111-1111-111111111111'),
  -- Also due to move, and the one the failure cases have to leave alone.
  ('0f8fad5b-1111-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-b', 'enc-b', 'buildpic-lossless', 'units/bar/buildpic/enc-b-Zx91Kp2w.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'approved', 'moderator', null, '11111111-1111-1111-1111-111111111111'),
  -- Still in the queue.
  ('0f8fad5b-1111-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-c', 'enc-c', 'buildpic-lossless', 'units/bar/buildpic/enc-c-Qw3eR9tY.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'pending', null, null, '11111111-1111-1111-1111-111111111111'),
  -- The one that is final.
  ('0f8fad5b-1111-4000-8000-00000000000d', 'bar', 'armpw', 'buildpic', 'src-d', 'enc-d', 'buildpic-lossless', 'units/bar/buildpic/enc-d-Lm5nB7vC.webp', 'blob', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'rejected', null, 'safety', '11111111-1111-1111-1111-111111111111');

-- ## Who may run it
--
-- Not a session. Promotion runs on a schedule with the secret key and nobody
-- behind it, which is the opposite of the moderation functions in
-- 20260814220200 and for the same reason: those exist so that a decision is
-- attributed to the person who made it, and this is not a decision.

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.promote_assets(ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid], ARRAY['units/bar/buildpic/enc-a.webp'])$$,
  '42501',
  null,
  'a signed in account cannot promote anything'
);

select throws_ok(
  $$select public.clear_promoted_blob_paths(ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid])$$,
  '42501',
  null,
  'nor forget a staging object that is still there'
);

reset role;
set local role anon;
set local request.jwt.claims = '';

select throws_ok(
  $$select public.promote_assets(ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid], ARRAY['units/bar/buildpic/enc-a.webp'])$$,
  '42501',
  null,
  'and neither can anybody holding the publishable key'
);

-- ## The job itself

reset role;
set local role service_role;
set local request.jwt.claims = '';

select throws_ok(
  $$select public.promote_assets(
      ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid, '0f8fad5b-1111-4000-8000-00000000000b'::uuid],
      ARRAY['units/bar/buildpic/enc-a.webp'])$$,
  '22023',
  null,
  'a batch with a path missing is refused rather than moving whichever rows line up'
);

select is(
  (select count(*) from public.asset where tier = 'static')::int,
  0,
  'and nothing moved when it was'
);

-- The batch the job would actually send: everything it selected, including the
-- two rows that turn out not to be eligible any more. The caller does not
-- filter, the statement does, and what comes back is the delete list.
select set_eq(
  $$select id::text || ' ' || blob_path from public.promote_assets(
      ARRAY[
        '0f8fad5b-1111-4000-8000-00000000000a'::uuid,
        '0f8fad5b-1111-4000-8000-00000000000b'::uuid,
        '0f8fad5b-1111-4000-8000-00000000000c'::uuid,
        '0f8fad5b-1111-4000-8000-00000000000d'::uuid
      ],
      ARRAY[
        'units/bar/buildpic/enc-a.webp',
        'units/bar/buildpic/enc-b.webp',
        'units/bar/buildpic/enc-c.webp',
        'units/bar/buildpic/enc-d.webp'
      ])$$,
  ARRAY[
    '0f8fad5b-1111-4000-8000-00000000000a units/bar/buildpic/enc-a-Hn4vQ2rT.webp',
    '0f8fad5b-1111-4000-8000-00000000000b units/bar/buildpic/enc-b-Zx91Kp2w.webp'
  ],
  'only the approved rows move, and each one comes back with the staging path it had'
);

select is(
  (select path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  'units/bar/buildpic/enc-a.webp',
  'a moved row points at the content addressed path'
);

select is(
  (select tier from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  'static',
  'and says which tier serves it'
);

select ok(
  (select promoted_at is not null from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  'and when it moved'
);

select is(
  (select blob_path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  'units/bar/buildpic/enc-a-Hn4vQ2rT.webp',
  'and still names the staging object, which is the only record of where it is'
);

select is(
  (select tier || ' ' || path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000c'),
  'blob units/bar/buildpic/enc-c-Qw3eR9tY.webp',
  'a picture still in the queue was left exactly as it was'
);

select is(
  (select tier || ' ' || path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000d'),
  'blob units/bar/buildpic/enc-d-Lm5nB7vC.webp',
  'and so was the safety rejection, without the trigger having to refuse anything'
);

-- The trigger is underneath all the same, and this is what it would do if the
-- filter above ever stopped catching one: the whole statement raises, so a
-- batch that reached a safety rejection would move none of its rows rather
-- than some of them.
select throws_ok(
  $$update public.asset set tier = 'static'
    where id in ('0f8fad5b-1111-4000-8000-00000000000c', '0f8fad5b-1111-4000-8000-00000000000d')$$,
  '23514',
  null,
  'one safety rejection in a batch fails the batch'
);

select is(
  (select tier from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000c'),
  'blob',
  'and the row alongside it did not half move'
);

-- ## Promotion does not run twice over the same row

select is(
  (select count(*)::int from public.promote_assets(
    ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid],
    ARRAY['units/bar/buildpic/enc-a.webp'])),
  0,
  'a row already on the durable tier has nothing to promote'
);

-- The case this guards is a run that died before deleting. The row is back on
-- the staging tier with a fresh object, and the old one is still queued, so
-- promoting again would overwrite the queue entry and lose the object it names.
update public.asset
set tier = 'blob', path = 'units/bar/buildpic/enc-b-New4Suffix.webp', moderation = 'approved'
where id = '0f8fad5b-1111-4000-8000-00000000000b';

select is(
  (select count(*)::int from public.promote_assets(
    ARRAY['0f8fad5b-1111-4000-8000-00000000000b'::uuid],
    ARRAY['units/bar/buildpic/enc-b.webp'])),
  0,
  'a row whose last staging object has not been deleted yet waits for the next run'
);

select is(
  (select blob_path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000b'),
  'units/bar/buildpic/enc-b-Zx91Kp2w.webp',
  'so the object nothing else names is still named'
);

-- ## Forgetting a staging object, once it is actually gone

select is(
  public.clear_promoted_blob_paths(ARRAY[
    '0f8fad5b-1111-4000-8000-00000000000a'::uuid,
    '0f8fad5b-1111-4000-8000-00000000000c'::uuid
  ]),
  1,
  'clearing answers with how many entries there were, not how many ids were asked about'
);

select is(
  (select blob_path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  null,
  'the cleared one is empty'
);

select is(
  (select tier || ' ' || path from public.asset where id = '0f8fad5b-1111-4000-8000-00000000000a'),
  'static units/bar/buildpic/enc-a.webp',
  'and clearing it changed nothing else about the row'
);

select is(
  public.clear_promoted_blob_paths(ARRAY['0f8fad5b-1111-4000-8000-00000000000a'::uuid]),
  0,
  'and doing it twice is not an error, which is what makes the drain safe to repeat'
);

-- ## The move is not a moderation decision

select is(
  (select count(*)::int from public.asset_event where asset_id = '0f8fad5b-1111-4000-8000-00000000000a'),
  1,
  'promoting a row logs nothing, because the only thing it changed is where the bytes live'
);

select * from finish();
rollback;
