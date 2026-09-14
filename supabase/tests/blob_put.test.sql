-- Counting put() the way Vercel does: a rolling 30 days, every kind of upload,
-- reserved before the write.

begin;
select plan(11);

create extension if not exists pgtap with schema extensions;

set local role service_role;
set local request.jwt.claims = '';

select isnt(
  public.reserve_blob_put('asset', 3),
  null,
  'a put() is reserved while there is room'
);

select isnt(
  public.reserve_blob_put('game_image', 3),
  null,
  'a game picture spends from the same allowance'
);

reset role;

-- Outside the window, so it is history rather than spend.
insert into public.blob_put (kind, at) values ('asset', now() - interval '31 days');

-- Inside it, and the reason a calendar month was wrong: late last month still
-- counts today.
insert into public.blob_put (kind, at) values ('asset', now() - interval '29 days');

set local role service_role;

select is(
  public.reserve_blob_put('asset', 3),
  null,
  'a put() from 29 days ago still counts, so the third reservation is refused'
);

select is(
  (select count(*)::int from public.blob_put where at > now() - interval '30 days'),
  3,
  'and a refusal writes nothing'
);

select isnt(
  public.reserve_blob_put('asset', 4),
  null,
  'the one from 31 days ago does not count at all'
);

select is(
  public.release_blob_put((select max(id) from public.blob_put)),
  true,
  'a reservation the store refused outright can be given back'
);

select is(
  public.release_blob_put((select max(id) from public.blob_put) + 1000),
  false,
  'and giving back one that does not exist says so'
);

select throws_ok(
  $$insert into public.blob_put (kind) values ('asset')$$,
  '42501',
  null,
  'the secret key cannot add to the count except by reserving'
);

select throws_ok(
  $$delete from public.blob_put$$,
  '42501',
  null,
  'nor empty it to make room'
);

select throws_ok(
  $$select public.reserve_blob_put('thumbnail', 10)$$,
  '23514',
  null,
  'only the kinds of upload that exist'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select throws_ok(
  $$select public.reserve_blob_put('asset', 10)$$,
  '42501',
  null,
  'a browser cannot spend from the allowance'
);

select * from finish();
rollback;
