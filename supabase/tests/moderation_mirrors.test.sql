-- The moderation mirrors page: listing, adding, removing and switching back on.

begin;
select plan(24);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('a1111111-0000-4000-8000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('a1111111-0000-4000-8000-000000000002', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('a1111111-0000-4000-8000-000000000002', 'can_moderate');

insert into public.mirror (id, name, base_url, public_key, enabled, healthy)
values
  ('m-up', 'Up', 'https://up.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true),
  ('m-held', 'Held', 'https://held.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true);

insert into public.archive (id, sha256, filename, kind, internal_name, version, size, upload_mirror_id, state, listed_at)
values ('22222222-0000-4000-8000-000000000001', repeat('1', 64), 'one.sd7', 'map', 'One', '1', 10, 'm-up', 'listed', now());

insert into public.archive_mirror (archive_id, mirror_id)
values ('22222222-0000-4000-8000-000000000001', 'm-up'), ('22222222-0000-4000-8000-000000000001', 'm-held');

-- ## Who may call

set local role anon;
select throws_ok($$select public.moderation_mirrors()$$, '42501', null, 'anon cannot list mirrors');
select throws_ok($$select public.moderation_add_mirror('a', 'A', 'https://a.test', repeat('A', 43))$$, '42501', null, 'anon cannot add a mirror');
select throws_ok($$select public.moderation_remove_mirror('m-up')$$, '42501', null, 'anon cannot remove a mirror');
select throws_ok($$select public.moderation_enable_mirror('m-up')$$, '42501', null, 'anon cannot switch a mirror on');
reset role;

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000001","role":"authenticated"}';
select is(public.moderation_mirrors(), null, 'a stranger gets no list');
select is(public.moderation_add_mirror('a', 'A', 'https://a.test', repeat('A', 43)), 'not_allowed', 'a stranger cannot add');
select is(public.moderation_remove_mirror('m-held'), 'not_allowed', 'a stranger cannot remove');
select is(public.moderation_enable_mirror('m-held'), 'not_allowed', 'a stranger cannot switch on');
reset role;

select is((select count(*)::int from public.mirror where id in ('a', 'm-up', 'm-held')), 2, 'the stranger changed nothing');

-- ## The moderator

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000002","role":"authenticated"}';

select is(
  (select m->>'copies' from jsonb_array_elements(public.moderation_mirrors()->'mirrors') as m where m->>'id' = 'm-up'),
  '1', 'the list counts the copies a mirror holds');
select is(
  (select m->>'uploads' from jsonb_array_elements(public.moderation_mirrors()->'mirrors') as m where m->>'id' = 'm-up'),
  '1', 'the list counts the uploads that went through a mirror');
select ok(public.moderation_mirrors() ? 'seq', 'the list carries the current catalogue seq');

select is(public.moderation_add_mirror('Bad Id', 'A', 'https://a.test', repeat('A', 43)), 'bad_id', 'an id with capitals and spaces is refused');
select is(public.moderation_add_mirror('new', '  ', 'https://a.test', repeat('A', 43)), 'bad_name', 'a blank name is refused');
select is(public.moderation_add_mirror('new', 'New', 'https://a.test/', repeat('A', 43)), 'bad_url', 'a trailing slash is refused');
select is(public.moderation_add_mirror('new', 'New', 'ftp://a.test', repeat('A', 43)), 'bad_url', 'a URL that is not http is refused');
select is(public.moderation_add_mirror('new', 'New', 'https://a.test', 'short'), 'bad_key', 'a key of the wrong length is refused');
select is(public.moderation_add_mirror('m-up', 'New', 'https://a.test', repeat('A', 43)), 'id_taken', 'an id in use is refused');
select is(public.moderation_add_mirror(' new ', ' New ', ' https://new.test ', repeat('A', 43)), 'added', 'a good mirror is added, trimmed');

select is(public.moderation_remove_mirror('m-held'), 'removed', 'a mirror nobody uploaded through is deleted');
select is(public.moderation_remove_mirror('m-up'), 'switched_off', 'a mirror uploads went through is switched off');
select is(public.moderation_remove_mirror('gone'), 'not_found', 'removing a missing mirror says so');
select is(public.moderation_enable_mirror('m-up'), 'switched_on', 'a switched off mirror is switched back on');
reset role;

select results_eq(
  $$select id, name, base_url, enabled, healthy from public.mirror where id in ('new', 'm-up', 'm-held') order by id$$,
  $$values ('m-up'::text, 'Up'::text, 'https://up.example.test'::text, true, false), ('new', 'New', 'https://new.test', true, false)$$,
  'the new mirror starts unhealthy, the held one is gone, and the switched on one waits for a report');

select * from finish();
rollback;
