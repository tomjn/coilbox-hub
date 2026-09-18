-- Who may put a gallery item at the top of the listing, run as the roles
-- PostgREST actually uses.
--
-- The point of these six is that the rule holds at the data layer rather than
-- in whichever page remembered to check. An author may write the words on their
-- own item and may not feature it; a signed in stranger may not feature
-- anything; and the one path that works records who did it from the session
-- rather than from anything the caller sent.
--
-- Nothing here mentions a kind. The item below is a preset because it had to be
-- something, and the same six answers hold for a challenge or for whatever the
-- gallery carries next.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'author@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.item (id, kind, kind_version, title, container, author_id, author_name)
values
  ('0f8fad5b-0007-4000-8000-000000000042', 'preset', 1, 'A good one',
   '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}',
   'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Someone'),
  ('0f8fad5b-0007-4000-8000-000000000043', 'preset', 1, 'Withdrawn',
   '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}',
   'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Someone');

update public.item
set deleted_at = now()
where id = '0f8fad5b-0007-4000-8000-000000000043';

-- The moderator's own session, which is where auth.uid() comes from.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select is(
  public.set_item_featured('0f8fad5b-0007-4000-8000-000000000042', true),
  true,
  'a moderator can feature a published item'
);

select is(
  (select featured_by from public.item where id = '0f8fad5b-0007-4000-8000-000000000042'),
  'cccccccc-cccc-cccc-cccc-cccccccccccc'::uuid,
  'the moderator is recorded from their session, not from the call'
);

-- A withdrawn item is invisible to the gallery, so featuring it would be a
-- button that reported success and changed nothing.
select is(
  public.set_item_featured('0f8fad5b-0007-4000-8000-000000000043', true),
  false,
  'a withdrawn item cannot be featured'
);

-- The author may still write the words on their own item, and may not write
-- this. The column grant refuses it before any policy is consulted.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$update public.item set title = 'A better name' where id = '0f8fad5b-0007-4000-8000-000000000042'$$,
  'the author may still rename their own item'
);

select throws_ok(
  $$update public.item set featured_at = null where id = '0f8fad5b-0007-4000-8000-000000000042'$$,
  '42501',
  null,
  'the author may not unfeature their own item'
);

select throws_ok(
  $$select public.set_item_featured('0f8fad5b-0007-4000-8000-000000000042', false)$$,
  '42501',
  null,
  'a signed in stranger may not unfeature it either'
);

select * from finish();
rollback;
