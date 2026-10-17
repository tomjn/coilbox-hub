-- Reporting a broken map (issue #294). The table is the item report table,
-- grown a second target, so what is proved here is the part that is new: a
-- report names exactly one thing, a map report says what kind of problem it
-- is, and the one-open-report cap that bounds anonymous reports holds per map
-- as it already does per item.

begin;
select plan(13);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'mod@example.test');

insert into public.user_capability (user_id, capability)
values ('11111111-1111-1111-1111-111111111111', 'can_moderate');

insert into public.item (id, kind, kind_version, title, container, author_id, author_name)
values ('aaaaaaaa-0000-0000-0000-000000000301', 'preset', 1, 'An item', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '11111111-1111-1111-1111-111111111111', 'Mod');

insert into public.map (id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
values
  ('0f8fad5b-0294-4000-8000-000000000001', 'Report Me 1.0', 'report-me-1-0', 8192, 8192, 0, 320, 'src-r1', 'report_me_1.0.sd7', 1, 'digest-r1'),
  ('0f8fad5b-0294-4000-8000-000000000002', 'Report Me Too 1.0', 'report-me-too-1-0', 8192, 8192, 0, 320, 'src-r2', 'report_me_too_1.0.sd7', 1, 'digest-r2');

set local role anon;

select lives_ok(
  $$insert into public.report (map_id, kind, reason)
    values ('0f8fad5b-0294-4000-8000-000000000001', 'crashes_engine', 'The engine closes on load')$$,
  'a visitor with no account can report a map'
);

select throws_ok(
  $$insert into public.report (map_id, kind, reason)
    values ('0f8fad5b-0294-4000-8000-000000000002', 'smells_funny', 'Not on the list')$$,
  '23514',
  null,
  'a kind outside the list is refused'
);

select throws_ok(
  $$insert into public.report (map_id, reason)
    values ('0f8fad5b-0294-4000-8000-000000000002', 'No kind given')$$,
  '23514',
  null,
  'a map report without a kind is refused'
);

select throws_ok(
  $$insert into public.report (item_id, kind, reason)
    values ('aaaaaaaa-0000-0000-0000-000000000301', 'other', 'An item has no kind')$$,
  '23514',
  null,
  'an item report with a kind is refused'
);

select throws_ok(
  $$insert into public.report (item_id, map_id, kind, reason)
    values ('aaaaaaaa-0000-0000-0000-000000000301', '0f8fad5b-0294-4000-8000-000000000002', 'other', 'Two targets')$$,
  '23514',
  null,
  'a report with two targets is refused'
);

select throws_ok(
  $$insert into public.report (kind, reason) values ('other', 'No target')$$,
  '23514',
  null,
  'a report with no target is refused'
);

select lives_ok(
  $$insert into public.report (map_id, kind, reason)
    values ('0f8fad5b-0294-4000-8000-000000000001', 'wrong_map', 'A second report on the same map')$$,
  'a second report on a map with an open report does not error'
);

select lives_ok(
  $$insert into public.report (item_id, reason)
    values ('aaaaaaaa-0000-0000-0000-000000000301', 'Item reports are unchanged')$$,
  'an item report with no kind is still stored'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from public.report where map_id = '0f8fad5b-0294-4000-8000-000000000001')::int, 1,
  'the duplicate was not stored, so the map still has one report'
);

select is(
  (select kind from public.report where map_id = '0f8fad5b-0294-4000-8000-000000000001'),
  'crashes_engine',
  'a moderator reads the kind that was stored'
);

select is(
  (select count(*) from public.report where item_id = 'aaaaaaaa-0000-0000-0000-000000000301')::int, 1,
  'a map report did not touch the item cap'
);

update public.report set handled_at = now(), handled_by = '11111111-1111-1111-1111-111111111111'
  where map_id = '0f8fad5b-0294-4000-8000-000000000001' and handled_at is null;

reset role;
set local role anon;

select lives_ok(
  $$insert into public.report (map_id, kind, reason)
    values ('0f8fad5b-0294-4000-8000-000000000001', 'files_missing', 'A new problem after the first was handled')$$,
  'a map can be reported again once its report is handled'
);

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"11111111-1111-1111-1111-111111111111","role":"authenticated"}';

select is(
  (select count(*) from public.report where map_id = '0f8fad5b-0294-4000-8000-000000000001' and handled_at is null)::int, 1,
  'and only the new one is open'
);

select * from finish();
rollback;
