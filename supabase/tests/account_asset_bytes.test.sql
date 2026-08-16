-- public.account_asset_bytes(uuid), the per account storage total the upload
-- route checks before it writes anything (issue #104).
--
-- Two things are being proved. That the total is the account's own and not
-- everybody's, because a quota that counts the wrong rows either refuses a new
-- account or lets a full one carry on. And that only the secret key can ask,
-- because execute is granted to PUBLIC on every new function and the revoke in
-- the migration is the whole of the access control.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'quota-a@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'quota-b@example.test');

insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values
  ('BYAR', 'armsolar', 'buildpic', 'src-1', 'enc-1', 'webp-lossless-256', 'units/BYAR/buildpic/enc-1.webp', 'extracted', 'image/webp', 4096, 128, 128, 'byar.sdz', '22222222-2222-2222-2222-222222222222'),
  ('BYAR', 'armcom', 'buildpic', 'src-2', 'enc-2', 'webp-lossless-256', 'units/BYAR/buildpic/enc-2.webp', 'extracted', 'image/webp', 6000, 128, 128, 'byar.sdz', '22222222-2222-2222-2222-222222222222'),
  ('BYAR', 'armllt', 'buildpic', 'src-3', 'enc-3', 'webp-lossless-256', 'units/BYAR/buildpic/enc-3.webp', 'extracted', 'image/webp', 9000, 128, 128, 'byar.sdz', '33333333-3333-3333-3333-333333333333');

-- A seeded row belongs to nobody, so it must not land on anybody's quota.
insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive, moderation, approval_source)
values
  ('Tangerine 1.1', 'minimap', 'src-4', 'enc-4', 'webp-q80-512', 'maps/minimap/enc-4.webp', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'tangerine_1.1.sd7', 'approved', 'seed');

reset role;
set local role service_role;

select is(
  public.account_asset_bytes('22222222-2222-2222-2222-222222222222'), 10096::bigint,
  'the total is the sum of that account uploads and nothing else'
);

select is(
  public.account_asset_bytes('33333333-3333-3333-3333-333333333333'), 9000::bigint,
  'and another account is counted separately'
);

select is(
  public.account_asset_bytes('44444444-4444-4444-4444-444444444444'), 0::bigint,
  'an account that has uploaded nothing totals zero rather than null, so a first upload compares against a number'
);

-- Nobody holding the publishable key may ask. The answer would total another
-- account one call at a time, which is not something any page needs.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select throws_ok(
  $$select public.account_asset_bytes('22222222-2222-2222-2222-222222222222')$$,
  '42501',
  null,
  'a visitor cannot ask what an account is storing'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"22222222-2222-2222-2222-222222222222","role":"authenticated"}';

select throws_ok(
  $$select public.account_asset_bytes('22222222-2222-2222-2222-222222222222')$$,
  '42501',
  null,
  'nor can an account ask about its own, since nothing in a browser has a use for it'
);

reset role;

-- Security invoker rather than definer, so the function adds no privilege of
-- its own and answers for the rows the caller can already see.
select is(
  (select prosecdef from pg_proc where oid = 'public.account_asset_bytes(uuid)'::regprocedure), false,
  'the function is security invoker'
);

select * from finish();
rollback;
