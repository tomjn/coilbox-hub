-- The access model is four policies and nothing else, so it is worth proving
-- rather than reading. These run as the roles PostgREST actually uses, `anon`
-- for a visitor with no session and `authenticated` for someone signed in with
-- Discord, because a policy that only holds for the owning superuser holds for
-- nobody.

begin;
select plan(16);

create extension if not exists pgtap with schema extensions;

-- Two authors, so "your own" can be told apart from "someone else's".
insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'ada@example.test'),
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'grace@example.test');

insert into public.item (id, kind, kind_version, title, container, author_id, author_name)
values
  ('aaaaaaaa-0000-0000-0000-000000000001', 'preset', 1, 'Ada live', '{"format":"coilbox"}', '11111111-1111-1111-1111-111111111111', 'Ada'),
  ('aaaaaaaa-0000-0000-0000-000000000002', 'preset', 1, 'Ada withdrawn', '{"format":"coilbox"}', '11111111-1111-1111-1111-111111111111', 'Ada');

update public.item set deleted_at = now() where id = 'aaaaaaaa-0000-0000-0000-000000000002';

-- A visitor with no account at all.
set local role anon;

select is(
  (select count(*) from public.item)::int, 1,
  'anon sees the live item and not the withdrawn one'
);

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id, author_name)
    values ('preset', 1, 'Spam', '{}', '11111111-1111-1111-1111-111111111111', 'Nobody')$$,
  '42501',
  null,
  'anon cannot publish'
);

select throws_ok(
  $$update public.item set title = 'Defaced'$$,
  '42501',
  null,
  'anon cannot edit'
);

select is(
  (select count(*) from public.item where id = 'aaaaaaaa-0000-0000-0000-000000000001')::int, 1,
  'anon delete removes nothing, because the policy hides every row from it'
);

reset role;

-- Signed in as Ada.
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from public.item)::int, 2,
  'an author sees their own withdrawn item, so withdrawing is reversible'
);

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id, author_name)
    values ('scenario', 1, 'Ada second', '{"format":"coilbox"}', '11111111-1111-1111-1111-111111111111', 'Ada')$$,
  'an author can publish as themselves'
);

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id, author_name)
    values ('preset', 1, 'Forged', '{"format":"coilbox"}', '22222222-2222-2222-2222-222222222222', 'Grace')$$,
  '42501',
  null,
  'an author cannot publish in somebody else''s name'
);

select lives_ok(
  $$update public.item set title = 'Ada renamed'
    where id = 'aaaaaaaa-0000-0000-0000-000000000001'$$,
  'an author can edit their own item'
);

select throws_ok(
  $$update public.item set author_id = '22222222-2222-2222-2222-222222222222'
    where id = 'aaaaaaaa-0000-0000-0000-000000000001'$$,
  '42501',
  null,
  'an author cannot hand their item to somebody else'
);

-- The column level update grant, in both directions. Editing an item means the
-- words around it, not the thing itself, so a changed payload cannot appear under
-- a URL somebody already shared.
select throws_ok(
  $$update public.item set container = '{"format":"tampered"}'
    where id = 'aaaaaaaa-0000-0000-0000-000000000001'$$,
  '42501',
  null,
  'an author cannot swap the container under an existing item'
);

select lives_ok(
  $$update public.item set deleted_at = now()
    where id = 'aaaaaaaa-0000-0000-0000-000000000001'$$,
  'an author can withdraw their own item'
);

-- Put it back, because the checks below need it visible. This also exercises the
-- reason an author can see their own withdrawn items: without that, the row would
-- now be invisible to Ada and this restore would silently affect nothing.
update public.item set deleted_at = null
  where id = 'aaaaaaaa-0000-0000-0000-000000000001';

reset role;

-- Signed in as Grace, who owns nothing.
set local role authenticated;
set local request.jwt.claims = '{"sub":"22222222-2222-2222-2222-222222222222","role":"authenticated"}';

select is(
  (select count(*) from public.item where title = 'Ada renamed')::int, 1,
  'another author can read a live item'
);

select is(
  (select count(*) from public.item where id = 'aaaaaaaa-0000-0000-0000-000000000002')::int, 0,
  'another author cannot see a withdrawn item'
);

select is(
  (select count(*) from public.item where id = 'aaaaaaaa-0000-0000-0000-000000000001')::int, 1,
  'another author deleting it removes nothing'
);

reset role;

-- The generated mode column, which is what tells warpath and conquest apart.
insert into public.item (kind, kind_version, title, container, author_id, author_name)
values (
  'challenge', 1, 'A run',
  '{"format":"coilbox","kind":"challenge","payload":{"mode":"warpath"}}',
  '11111111-1111-1111-1111-111111111111', 'Ada'
);

select is(
  (select mode from public.item where title = 'A run'), 'warpath',
  'mode is taken from the payload rather than trusted from the client'
);

select is(
  (select mode from public.item where title = 'Ada live'), null,
  'a kind with no mode has none, so this only ever narrows challenges'
);

select * from finish();
rollback;
