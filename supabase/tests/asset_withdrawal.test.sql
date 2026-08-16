-- A safety rejection reaching a picture that has already been promoted (issue
-- #153).
--
-- The thing being proved is that the record happens without the freeze being
-- touched. asset_safety_rejection_is_final still refuses every change to the
-- row it refused before, the row is still frozen after the withdrawal is
-- recorded, and the withdrawal is written anyway, by a trigger, into a table
-- nobody holds insert on.
--
-- The rest of it is about what does not get recorded, which is most of the
-- interesting part: a staging rejection, an editorial one, and a promoted row
-- that a newer archive has already replaced.

begin;
select plan(17);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('33333333-3333-3333-3333-333333333333', 'can_moderate');

-- Four approved pictures. Two are in the durable tier and two are not, which is
-- the whole distinction this table is drawn on.
insert into public.asset (id, map_name, variant, source_hash, hash, encode_profile, path, tier, promoted_at, origin, mime, bytes, width, height, map_width, map_height, source_archive, moderation, approval_source, uploaded_by)
values
  ('0f8fad5b-0000-4000-8000-00000000000a', 'Tangerine 1.1', 'minimap', 'src-a', 'enc-promoted', 'webp-q80-512', 'maps/minimap/enc-promoted.webp', 'static', now(), 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'tangerine_1.1.sd7', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-0000-4000-8000-00000000000b', 'Comet Catcher 1.8', 'minimap', 'src-b', 'enc-staging', 'webp-q80-512', 'maps/minimap/enc-staging-Xy9.webp', 'blob', null, 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'comet_1.8.sd7', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-0000-4000-8000-00000000000c', 'Supreme Isthmus 1.2', 'minimap', 'src-c', 'enc-editorial', 'webp-q80-512', 'maps/minimap/enc-editorial.webp', 'static', now(), 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'isthmus_1.2.sd7', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111'),
  ('0f8fad5b-0000-4000-8000-00000000000d', 'Quicksilver 1.5', 'minimap', 'src-d', 'enc-replaced', 'webp-q80-512', 'maps/minimap/enc-replaced-Ab3.webp', 'blob', null, 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'quicksilver_1.5.sd7', 'approved', 'moderator', '11111111-1111-1111-1111-111111111111');

select is(
  (select count(*) from public.asset_withdrawal)::int, 0,
  'promoting a picture asks for nothing, because approving one is not a problem'
);

-- ## The case the issue is about

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.reject_asset('0f8fad5b-0000-4000-8000-00000000000a', 'safety'),
  true,
  'a moderator rejects a picture that was promoted a while ago'
);

reset role;

select is(
  (select path from public.asset_withdrawal where asset_id = '0f8fad5b-0000-4000-8000-00000000000a'),
  'maps/minimap/enc-promoted.webp',
  'and the durable path it has to come out of is recorded, without anybody asking'
);

select is(
  (select withdrawn_at from public.asset_withdrawal where asset_id = '0f8fad5b-0000-4000-8000-00000000000a'),
  null,
  'outstanding, which is the only state a new one has'
);

-- The freeze is untouched, which is the reason a side table was used at all.
select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator'
    where id = '0f8fad5b-0000-4000-8000-00000000000a'$$,
  '23514',
  null,
  'the row is still frozen afterwards, so recording the takedown widened nothing'
);

select throws_ok(
  $$update public.asset set tier = 'blob' where id = '0f8fad5b-0000-4000-8000-00000000000a'$$,
  '23514',
  null,
  'including its tier, which is why the record could not have lived on the row'
);

select is(
  (select array_agg(action || ':' || coalesce(rejection_kind, '-') order by id)
    from public.asset_event where asset_id = '0f8fad5b-0000-4000-8000-00000000000a'),
  ARRAY['approved:-', 'rejected:safety'],
  'and the decision itself is in the log, which is the record this one is not'
);

-- ## What is not recorded

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.reject_asset('0f8fad5b-0000-4000-8000-00000000000b', 'safety'),
  true,
  'a picture still in staging is rejected the same way'
);

select is(
  public.reject_asset('0f8fad5b-0000-4000-8000-00000000000c', 'editorial'),
  true,
  'and a promoted one is turned away as an editorial call'
);

reset role;

select is(
  (select count(*) from public.asset_withdrawal
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000b')::int,
  0,
  'staging asks for nothing: the object is deletable and no history holds a copy'
);

select is(
  (select count(*) from public.asset_withdrawal
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000c')::int,
  0,
  'and an editorial rejection asks for nothing, because return_asset can undo it'
);

-- A promoted picture that a newer archive has already replaced. The bytes this
-- rejection is about are the replacement's, in Blob. The superseded durable
-- object was approved and judged by nobody, so it is #113's orphan.
select is(
  (select count(*) from public.asset_withdrawal
    where asset_id = '0f8fad5b-0000-4000-8000-00000000000d')::int,
  0,
  'nor does a row a replacement has already put back on the staging tier'
);

-- ## Who may write it

reset role;
set local role service_role;
set local request.jwt.claims = '';

select throws_ok(
  $$insert into public.asset_withdrawal (asset_id, path)
    values ('0f8fad5b-0000-4000-8000-00000000000c', 'maps/minimap/enc-editorial.webp')$$,
  '42501',
  null,
  'nothing holding the secret key can add to the queue by hand'
);

select throws_ok(
  $$update public.asset_withdrawal set withdrawn_at = now()$$,
  '42501',
  null,
  'nor settle a row without going through the function that only settles once'
);

select is(
  public.record_asset_withdrawn(ARRAY['0f8fad5b-0000-4000-8000-00000000000a'::uuid]),
  1,
  'the maintainer who removed the file says so, and the queue empties'
);

select is(
  public.record_asset_withdrawn(ARRAY['0f8fad5b-0000-4000-8000-00000000000a'::uuid]),
  0,
  'saying it twice settles nothing, so a repeat is not a second takedown'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select throws_ok(
  $$select public.record_asset_withdrawn(ARRAY['0f8fad5b-0000-4000-8000-00000000000a'::uuid])$$,
  '42501',
  null,
  'and a moderator in a browser cannot, because this is not a decision made on a screen'
);

select * from finish();
rollback;
