-- The staged pictures bucket (issue #330): it exists, it is private, and
-- neither `anon` nor `authenticated` can list, read or write it, whatever
-- table grant sits underneath. `storage.buckets` and `storage.objects` both
-- have row level security on and no policy names either role, so there is
-- nothing for a permissive rule to match against, not a rule that happens
-- not to fire.

begin;
select plan(14);

create extension if not exists pgtap with schema extensions;

-- Read as postgres, which owns the tables and bypasses row level security,
-- so these are the facts about the bucket itself rather than what a role
-- can see of it.
select ok(
  exists(select 1 from storage.buckets where id = 'staged-pictures'),
  'the bucket exists'
);

select is(
  (select public from storage.buckets where id = 'staged-pictures'), false,
  'and is private'
);

select is(
  (select file_size_limit from storage.buckets where id = 'staged-pictures'), 2097152::bigint,
  'with the larger of the two byte ceilings a staged picture can arrive under'
);

select is(
  (select allowed_mime_types from storage.buckets where id = 'staged-pictures'),
  ARRAY['image/webp', 'image/png']::text[],
  'and only the two types the hub can name a stored file after'
);

select is(
  (select count(*) from pg_policy where polrelid = 'storage.buckets'::regclass)::int, 0,
  'no policy on storage.buckets'
);

select is(
  (select count(*) from pg_policy where polrelid = 'storage.objects'::regclass)::int, 0,
  'no policy on storage.objects'
);

select is(
  (select relrowsecurity from pg_class where oid = 'storage.buckets'::regclass), true,
  'row level security is on for storage.buckets'
);

select is(
  (select relrowsecurity from pg_class where oid = 'storage.objects'::regclass), true,
  'row level security is on for storage.objects'
);

-- A visitor with no session.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from storage.buckets where id = 'staged-pictures')::int, 0,
  'a visitor cannot see the bucket in a list'
);

select is(
  (select count(*) from storage.objects where bucket_id = 'staged-pictures')::int, 0,
  'nor list anything staged in it'
);

select throws_ok(
  $$insert into storage.objects (bucket_id, name) values ('staged-pictures', 'probe.webp')$$,
  '42501',
  null,
  'nor write an object into it'
);

-- Somebody signed in with Discord.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from storage.buckets where id = 'staged-pictures')::int, 0,
  'an account cannot see the bucket in a list either'
);

select is(
  (select count(*) from storage.objects where bucket_id = 'staged-pictures')::int, 0,
  'nor list anything staged in it'
);

select throws_ok(
  $$insert into storage.objects (bucket_id, name) values ('staged-pictures', 'probe.webp')$$,
  '42501',
  null,
  'nor write an object into it'
);

reset role;

select * from finish();
rollback;
