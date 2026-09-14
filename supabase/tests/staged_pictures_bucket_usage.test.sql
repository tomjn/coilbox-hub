-- How full the staged-pictures bucket is (issue #337).
--
-- storage.get_size_by_bucket() is Supabase's, not a migration here, so what
-- this proves is the wrapper: it sums only the named bucket, it is zero
-- rather than null when the bucket holds nothing, and who may call it.

begin;
select plan(5);

create extension if not exists pgtap with schema extensions;

-- ## Zero, not null, when the bucket holds nothing

select is(
  public.staged_pictures_bucket_bytes(),
  0::bigint,
  'an empty bucket reads as zero bytes, not null'
);

-- A second bucket, to prove the sum does not bleed across buckets.
insert into storage.buckets (id, name) values ('other-bucket', 'other-bucket');

insert into storage.objects (bucket_id, name, metadata)
values
  ('staged-pictures', 'units/bar/buildpic/enc-a.webp', '{"size": 4096}'),
  ('staged-pictures', 'units/bar/buildpic/enc-b.webp', '{"size": 2048}'),
  ('other-bucket', 'someone/avatar.webp', '{"size": 999999}');

select is(
  public.staged_pictures_bucket_bytes(),
  6144::bigint,
  'sums only the staged-pictures bucket'
);

-- ## Who may call it

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.staged_pictures_bucket_bytes()$$,
  '42501', null,
  'a signed in account cannot read the bucket meter'
);

reset role;
set local role anon;
set local request.jwt.claims = '';

select throws_ok(
  $$select public.staged_pictures_bucket_bytes()$$,
  '42501', null,
  'nor can anybody holding the publishable key'
);

select function_privs_are('public', 'staged_pictures_bucket_bytes', ARRAY[]::text[],
  'service_role', ARRAY['EXECUTE'],
  'only the secret key may call it, the same as the other meters');

select * from finish();
rollback;
