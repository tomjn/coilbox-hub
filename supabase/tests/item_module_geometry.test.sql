-- Unit builder modules (issue #507): a module of parts can be stored, and a
-- module holding geometry lifted from a game cannot, even when a signed in
-- author inserts straight through PostgREST and skips accept().

begin;
select plan(12);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('44444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'module@example.test');

set local role authenticated;
set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated"}';

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"coilbox-parts","rootPieceId":"root","pieces":[{"id":"root","name":"base"},{"id":"p1","name":"turret","partId":"turret-round"}],"game":null}}', '44444444-4444-4444-4444-444444444444')$$,
  'a module of parts and empty pieces is stored');

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"root","lifted":null}]}}', '44444444-4444-4444-4444-444444444444')$$,
  'a lifted field holding null is no lifted record, as in the TypeScript');

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[[{"lifted":{}}], 3, null, "x"]}}', '44444444-4444-4444-4444-444444444444')$$,
  'a piece that is not an object is passed over, as in the TypeScript');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"root"},{"id":"p2","name":"barrel","meshId":"m","lifted":{"game":{"name":"Beyond All Reason","shortname":"BAR"}}}],"game":{"name":"Beyond All Reason","shortname":"BAR"}}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'a module holding a lifted piece is refused, even tagged to the game it came from');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"p2","lifted":{}}]}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'a lifted record alone is refused, with or without a mesh');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"p3","name":"hull","meshId":"m"}]}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'an imported mesh with no lifted record is refused');

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"p3","meshId":5}]}}', '44444444-4444-4444-4444-444444444444')$$,
  'a meshId that is not a string is no mesh, as in the TypeScript');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root"}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'a module with no pieces array is refused');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('module', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":{"a":{"lifted":{}}}}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'pieces that are not an array are refused');

select throws_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('preset', 1, 'Module test', '{"format":"coilbox","container":1,"kind":"module","kindVersion":1,"payload":{"packId":"p","rootPieceId":"root","pieces":[{"id":"p2","lifted":{}}]}}', '44444444-4444-4444-4444-444444444444')$$,
  '23514', null,
  'a lifted module in a row labelled as another kind is refused, because coilbox imports by the envelope');

select lives_ok(
  $$insert into public.item (kind, kind_version, title, container, author_id)
    values ('preset', 1, 'Preset with pieces', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{"pieces":[{"lifted":{}}]}}', '44444444-4444-4444-4444-444444444444')$$,
  'other kinds are untouched by the module rule');

reset role;

select is(
  (select count(*)::integer from public.item where author_id = '44444444-4444-4444-4444-444444444444' and kind = 'module'),
  4, 'only the four allowed modules were stored');

select * from finish();
rollback;
