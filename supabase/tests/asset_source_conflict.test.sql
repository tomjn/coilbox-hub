-- Two clients disagreeing about what one archive contains (issue #116).
--
-- The table is a cheap signal and it has to stay one, so most of what is proved
-- here is about what recording a disagreement does *not* do. It does not move
-- the asset, does not touch its moderation state, does not appear in the audit
-- trail, and does not reach a browser.
--
-- The rest is the shape: one row per distinct set of reported bytes, so a
-- client looping on the same refused upload cannot fill the table, and a
-- conflict is always between two hashes that actually differ.

begin;
select plan(14);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test');

-- One approved picture out of bar_1.2.sdz, and one rejected on safety grounds.
insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by, moderation, approval_source)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar', 'armsolar', 'buildpic', 'src-one', 'enc-one', 'buildpic-q80', 'unit/bar/armsolar/buildpic/enc-one.webp', 'extracted', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111', 'approved', 'moderator');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by, moderation, rejection_kind)
values ('0f8fad5b-0000-4000-8000-0000000000a2', 'bar', 'armllt', 'buildpic', 'src-two', 'enc-two', 'buildpic-q80', 'unit/bar/armllt/buildpic/enc-two.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', '11111111-1111-1111-1111-111111111111', 'rejected', 'safety');

-- ## The shape

insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-other', '22222222-2222-2222-2222-222222222222');

select is(
  (select count(*) from public.asset_source_conflict)::int, 1,
  'a second account reporting different bytes out of one archive is recorded'
);

-- The upsert the route makes. The same bytes reported again are the same fact
-- reported again, so a client looping on a refused upload leaves one row.
insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-other', '22222222-2222-2222-2222-222222222222')
on conflict (asset_id, reported_source_hash) do nothing;

select is(
  (select count(*) from public.asset_source_conflict)::int, 1,
  'the same reported bytes again are the same record, not a second one'
);

select throws_ok(
  $$insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-other')$$,
  '23505',
  null,
  'and a plain insert of it is refused rather than duplicated'
);

-- A third set of bytes for the same picture is a different fact and is kept.
insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-third', '22222222-2222-2222-2222-222222222222');

select is(
  (select count(*) from public.asset_source_conflict)::int, 2,
  'a third set of bytes for the same picture is a second disagreement'
);

select throws_ok(
  $$insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-one')$$,
  '23514',
  null,
  'hashes that agree are not a disagreement'
);

select throws_ok(
  $$insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0000-4000-8000-0000000000ff', 'bar_1.2.sdz', 'src-one', 'src-other')$$,
  '23503',
  null,
  'a disagreement about a picture the hub does not hold is not a record of anything'
);

-- ## What it is not

-- The whole point. A flag that changed the state would be a gate, and the one
-- it would gate is somebody else's approved picture, which hands every signed
-- in account a way to take the corpus off the site one request at a time.
select is(
  (select moderation from public.asset where id = '0f8fad5b-0000-4000-8000-0000000000a1'),
  'approved',
  'recording a disagreement leaves the picture approved and serving'
);

select is(
  (select count(*) from public.asset_event where asset_id = '0f8fad5b-0000-4000-8000-0000000000a1')::int,
  1,
  'and adds nothing to the audit trail, which still holds only the approval'
);

-- The row that must not be touched. The application never records against a
-- rejected asset, and nothing here reaches into one even if it did: the
-- reference is one way.
insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0000-4000-8000-0000000000a2', 'bar_1.2.sdz', 'src-two', 'src-other', '22222222-2222-2222-2222-222222222222');

select is(
  (select moderation || '/' || rejection_kind from public.asset where id = '0f8fad5b-0000-4000-8000-0000000000a2'),
  'rejected/safety',
  'a safety rejection is untouched by anything recorded alongside it'
);

-- ## Who can see it

select ok(
  (select relrowsecurity from pg_class where oid = 'public.asset_source_conflict'::regclass),
  'row level security is on'
);

select is(
  (select count(*) from pg_policy where polrelid = 'public.asset_source_conflict'::regclass)::int,
  0,
  'with no policy, so the publishable key reads nothing whatever the grants say'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select * from public.asset_source_conflict$$,
  '42501',
  null,
  'a signed in account cannot read who reported what'
);

select throws_ok(
  $$insert into public.asset_source_conflict (asset_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0000-4000-8000-0000000000a1', 'bar_1.2.sdz', 'src-one', 'src-typed')$$,
  '42501',
  null,
  'and cannot manufacture a disagreement about a picture that is not theirs'
);

reset role;
set local role service_role;

select is(
  (select count(*) from public.asset_source_conflict where asset_id = '0f8fad5b-0000-4000-8000-0000000000a1')::int,
  2,
  'the secret key reads the marks, which is how the contact sheet knows which tiles to ring'
);

select * from finish();
rollback;
