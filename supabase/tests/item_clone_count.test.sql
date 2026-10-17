-- public.clone_count(public.item), the number of units a mod project adds
-- (issue #415).
--
-- Three things are being proved. That the count matches how the app reads
-- `clones` (lib/gallery/modProjectPreview.ts), including the odd shapes an
-- untrusted payload can hold. That a withdrawn item is as hidden through the
-- function as it is through the table. And that the function is executable by
-- the roles that read the gallery and nobody else.

begin;
select plan(11);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('44444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'clones@example.test');

insert into public.item (id, kind, kind_version, title, container, author_id, author_name, deleted_at)
values
  ('cccccccc-0000-0000-0000-000000000001', 'mod-project', 1, 'Two clones',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":{"armsolar2":{"source":"armsolar","metal":1},"armmex2":{"source":"armmex"}}}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000002', 'mod-project', 1, 'No clones',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":{}}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000003', 'mod-project', 1, 'No edits',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000004', 'mod-project', 1, 'Clones is a list',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":[{"source":"armsolar"}]}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000005', 'mod-project', 1, 'Keys normalise',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":{"ArmSolar2":{"source":"a"}," armsolar2 ":{"source":"b"},"":{"source":"c"},"   ":{"source":"d"},"armmex2":{"source":"e"}}}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000006', 'mod-project', 1, 'Values that are not units',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":{"a":null,"b":"text","c":3,"d":[1],"e":{"source":"armsolar"}}}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', null),
  ('cccccccc-0000-0000-0000-000000000007', 'mod-project', 1, 'Withdrawn',
   '{"format":"coilbox","container":1,"kind":"mod-project","kindVersion":1,"payload":{"edits":{"clones":{"armsolar2":{"source":"armsolar"}}}}}',
   '44444444-4444-4444-4444-444444444444', 'Clones', now());

select function_privs_are('public', 'clone_count', ARRAY['item'], 'anon', ARRAY['EXECUTE'],
  'anon can execute clone_count');
select function_privs_are('public', 'clone_count', ARRAY['item'], 'authenticated', ARRAY['EXECUTE'],
  'authenticated can execute clone_count');
select is(
  (select prosecdef from pg_proc where oid = 'public.clone_count(public.item)'::regprocedure), false,
  'clone_count runs as the caller, so row level security on item applies');

set local role anon;

select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000001'),
  2, 'every clone is counted');
select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000002'),
  0, 'an empty clones table is zero');
select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000003'),
  0, 'a payload with no edits is zero');
select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000004'),
  0, 'clones that is a list is zero');
select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000005'),
  2, 'keys are trimmed and lower cased, blanks dropped, and alike keys counted once');
select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000006'),
  1, 'only values that are objects count as clones');
select is(
  (select count(*)::integer from public.item as i
   where id = 'cccccccc-0000-0000-0000-000000000007' and public.clone_count(i) is not null),
  0, 'anon cannot reach a withdrawn item through clone_count');

reset role;
set local role authenticated;
set local request.jwt.claims to '{"sub": "44444444-4444-4444-4444-444444444444", "role": "authenticated"}';

select is(
  (select public.clone_count(i) from public.item as i where id = 'cccccccc-0000-0000-0000-000000000007'),
  1, 'the author can count the clones on their own withdrawn item');

select * from finish();
rollback;
