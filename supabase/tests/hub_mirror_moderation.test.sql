-- Removing archives, mismatch reports, and failed uploads that a later mirror
-- report brings back.

begin;
select plan(43);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('a1111111-0000-4000-8000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'uploader@example.test'),
  ('a1111111-0000-4000-8000-000000000002', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('a1111111-0000-4000-8000-000000000003', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('a1111111-0000-4000-8000-000000000004', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('a1111111-0000-4000-8000-000000000004', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0009-4000-8000-000000000001', 'MG', 'a1111111-0000-4000-8000-000000000002');

insert into public.mirror (id, name, base_url, public_key, enabled, healthy)
values
  ('m-x', 'X', 'https://x.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true),
  ('m-r', 'R', 'https://r.example.test', 'B17CkPdtRVPSfwdwz9CenRCdM6QSUBFMarO-10NppNY', true, true);

insert into public.archive (id, sha256, filename, kind, internal_name, version, shortname, size, uploader, game_id, state, listed_at, reserved_until)
values
  ('22222222-0000-4000-8000-000000000001', repeat('1', 64), 'one.sd7', 'map', 'One', '1', null, 10, 'a1111111-0000-4000-8000-000000000001', null, 'listed', now(), null),
  ('22222222-0000-4000-8000-000000000002', repeat('2', 64), 'two.sdz', 'game', 'MG 1', '1', 'MG', 10, null, '0f8fad5b-0009-4000-8000-000000000001', 'listed', now(), null),
  ('22222222-0000-4000-8000-000000000003', repeat('3', 64), 'three.sd7', 'map', 'Three', '1', null, 10, 'a1111111-0000-4000-8000-000000000003', null, 'listed', now(), null),
  ('22222222-0000-4000-8000-000000000004', repeat('4', 64), 'four.sd7', 'map', 'Four', '1', null, 10, 'a1111111-0000-4000-8000-000000000001', null, 'uploading', null, now() + interval '1 day'),
  ('22222222-0000-4000-8000-000000000005', repeat('5', 64), 'five.sd7', 'map', 'Five', '1', null, 10, 'a1111111-0000-4000-8000-000000000003', null, 'listed', now(), null);

insert into public.archive_mirror (archive_id, mirror_id)
values ('22222222-0000-4000-8000-000000000003', 'm-x');

create temporary table r (label text primary key, j jsonb);
grant all on r to public;

-- ## Who may call

set local role anon;
select throws_ok(
  $$select public.remove_archive('22222222-0000-4000-8000-000000000001', 'withdrawn', null)$$,
  '42501', null, 'anon cannot call remove_archive');
select throws_ok($$select public.my_archives()$$, '42501', null, 'anon cannot call my_archives');
reset role;

set local role authenticated;
select throws_ok(
  $$select public.report_archive_mismatch(repeat('5', 64), '{}'::jsonb, null)$$,
  '42501', null, 'a browser session cannot record a mismatch report');
reset role;

-- ## The uploader

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000001","role":"authenticated"}';

select is(public.remove_archive('22222222-0000-4000-8000-000000000003', 'withdrawn', null), 'not_allowed', 'a user cannot withdraw somebody else''s map');
select is(public.remove_archive('22222222-0000-4000-8000-000000000001', 'broken', null), 'not_allowed', 'only a moderator gives a reason other than withdrawn');
select is(public.remove_archive('22222222-0000-4000-8000-000000000004', 'withdrawn', null), 'not_listed', 'an upload still in progress cannot be withdrawn');
select is(public.remove_archive('22222222-0000-4000-8000-0000000000ff', 'withdrawn', null), 'not_found', 'an unknown archive is not_found');
select is(public.remove_archive('22222222-0000-4000-8000-000000000001', 'nonsense', null), 'bad_reason', 'an unknown reason is refused');
select is(public.remove_archive('22222222-0000-4000-8000-000000000001', 'withdrawn', 'a note'), 'removed', 'the uploader withdraws their own map');
select is(
  jsonb_array_length(public.my_archives()), 2,
  'my_archives lists the uploader''s withdrawn and in progress uploads');

reset role;

select ok(
  (select state = 'removed' and removal_reason = 'withdrawn' and removal_note is null
      and removed_by = 'a1111111-0000-4000-8000-000000000001' and removed_at is not null
   from public.archive where id = '22222222-0000-4000-8000-000000000001'),
  'withdrawing records the reason and who, and drops a note from a non-moderator');

-- ## The game's owner

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000002","role":"authenticated"}';

select is(public.remove_archive('22222222-0000-4000-8000-000000000002', 'withdrawn', null), 'removed', 'a game''s owner removes an archive of that game');
select is(
  (select (e->>'via_game')::boolean from jsonb_array_elements(public.my_archives()) as e where e->>'filename' = 'two.sdz'),
  true, 'my_archives shows a game owner the game''s uploads');

-- ## A stranger

set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000003","role":"authenticated"}';

select is(public.moderation_archives(''), null, 'a stranger gets nothing from moderation_archives');
select is(public.dismiss_archive_mismatches('22222222-0000-4000-8000-000000000005'), 0, 'a stranger cannot dismiss reports');

reset role;

-- ## Mismatch reports

select is(
  public.report_archive_mismatch(repeat('9', 64), '{"internal_name":"X"}', '10.0.0.1')->>'outcome',
  'unknown_archive', 'a hash the hub does not list is unknown');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"kind":"map","internal_name":"Five","version":"1","shortname":null}', '10.0.0.1')->>'outcome',
  'agrees', 'a report where every field agrees is refused');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"kind":"map","internal_name":"Not Five"}', '10.0.0.1')->>'outcome',
  'recorded', 'a report with a field that disagrees is recorded');
select is(
  (select found from public.archive_mismatch where archive_id = '22222222-0000-4000-8000-000000000005' and ip = '10.0.0.1'),
  '{"internal_name":"Not Five"}'::jsonb, 'only the fields that disagree are kept');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"version":"2"}', '10.0.0.1')->>'outcome',
  'already', 'the same address reporting the same archive again is already');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"version":"2"}', '10.0.0.2')->>'outcome',
  'recorded', 'another address adds its own report');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"version":"3"}', null)->>'outcome',
  'recorded', 'a report with no address is recorded');

-- 19 earlier reports from one address in the last hour, already handled.
insert into public.archive_mismatch (archive_id, found, ip, handled_at, handled_how)
select '22222222-0000-4000-8000-000000000005', '{"version":"x"}', '10.0.0.9', now(), 'dismissed'
from generate_series(1, 19);

select is(
  public.report_archive_mismatch(repeat('3', 64), '{"version":"2"}', '10.0.0.9')->>'outcome',
  'recorded', 'the 20th report in an hour is recorded');
select is(
  public.report_archive_mismatch(repeat('5', 64), '{"version":"2"}', '10.0.0.9')->>'outcome',
  'rate_limited', 'the 21st report in an hour is refused');

delete from public.archive_mismatch where ip = '10.0.0.9' and handled_at is not null;

-- ## The moderator

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000004","role":"authenticated"}';

insert into r select 'mod', public.moderation_archives('');
insert into r select 'five', public.moderation_archives(' FIVE ');

reset role;

select is(jsonb_array_length((select j->'reported' from r where label = 'mod')), 2, 'two archives are reported');
select is(
  (select jsonb_array_length(e->'reports') from jsonb_array_elements((select j->'reported' from r where label = 'mod')) as e where e->>'filename' = 'five.sd7'),
  3, 'each open report on an archive is listed');
select is(
  (select e->'copies' from jsonb_array_elements((select j->'reported' from r where label = 'mod')) as e where e->>'filename' = 'three.sd7'),
  '["https://x.example.test/maps/three.sd7"]'::jsonb, 'copies link the healthy mirrors that hold the archive');
select is(
  (select jsonb_agg(e->>'filename') from jsonb_array_elements((select j->'recent' from r where label = 'five')) as e),
  '["five.sd7"]'::jsonb, 'recent filters by part of the file name, in any case');

set local role authenticated;
set local request.jwt.claims = '{"sub":"a1111111-0000-4000-8000-000000000004","role":"authenticated"}';

select is(public.dismiss_archive_mismatches('22222222-0000-4000-8000-000000000005'), 3, 'dismissing closes every open report on the archive');
select is(public.remove_archive('22222222-0000-4000-8000-000000000003', 'other', '  '), 'note_required', 'other needs a note');
select is(public.remove_archive('22222222-0000-4000-8000-000000000003', 'rights', 'Asked by the author'), 'removed', 'a moderator removes any archive with a reason');

reset role;

select is(
  (select count(*) from public.archive_mismatch
    where archive_id = '22222222-0000-4000-8000-000000000005' and (ip is not null or handled_how <> 'dismissed')),
  0::bigint, 'dismissed reports lose their address');
select ok(
  (select bool_and(handled_how = 'removed' and ip is null) from public.archive_mismatch where archive_id = '22222222-0000-4000-8000-000000000003'),
  'removing closes the archive''s reports and clears their addresses');
select is(
  (select removal_note from public.archive where id = '22222222-0000-4000-8000-000000000003'),
  'Asked by the author', 'a moderator''s note is kept');

-- ## A later report brings back a failed upload

insert into public.archive (id, sha256, filename, kind, internal_name, version, size, state, upload_mirror_id, created_at)
values
  ('33333333-0000-4000-8000-000000000001', repeat('e', 64), 'six.sd7', 'map', 'Six', '1', 10, 'failed', 'm-r', now()),
  ('33333333-0000-4000-8000-000000000002', repeat('f', 64), 'seven.sd7', 'map', 'Seven', '1', 10, 'failed', 'm-r', now()),
  ('33333333-0000-4000-8000-000000000003', repeat('d', 64), 'eight-old.sd7', 'map', 'Eight old', '1', 10, 'failed', 'm-r', now() - interval '2 days'),
  ('33333333-0000-4000-8000-000000000004', repeat('d', 64), 'eight-new.sd7', 'map', 'Eight new', '1', 10, 'failed', 'm-r', now() - interval '1 day');

-- A newer upload took seven's name after it failed.
insert into public.archive (id, sha256, filename, kind, internal_name, version, size, state, listed_at)
values ('33333333-0000-4000-8000-000000000005', repeat('c', 64), 'Seven.sd7', 'map', 'Seven again', '1', 10, 'listed', now());

create function pg_temp.report(p_sent_at bigint, p_held text[])
returns jsonb language sql as $$
  select jsonb_build_object(
    'v', 1, 'event', 'sync', 'upload', null, 'sent_at', p_sent_at,
    'catalogue_seq', null, 'catalogue_generated_at', null,
    'held', to_jsonb(p_held), 'unknown', '[]'::jsonb
  )
$$;

insert into r select 'revive', public.record_mirror_report('m-r', pg_temp.report(100, array[repeat('e', 64), repeat('f', 64), repeat('d', 64)]));

select is((select (j->>'listed')::int from r where label = 'revive'), 2, 'two failed uploads are listed again');
select is((select state from public.archive where id = '33333333-0000-4000-8000-000000000001'), 'listed', 'a failed upload is revived');
select is((select state from public.archive where id = '33333333-0000-4000-8000-000000000002'), 'failed', 'one whose name was taken since stays failed');
select is((select state from public.archive where id = '33333333-0000-4000-8000-000000000004'), 'listed', 'of two failed rows with one hash, the newer is revived');
select is((select state from public.archive where id = '33333333-0000-4000-8000-000000000003'), 'failed', 'and the older stays failed');
select is((select (j->>'changed')::boolean from r where label = 'revive'), true, 'a report that lists something says it changed');
select is(
  (public.record_mirror_report('m-r', pg_temp.report(101, array[repeat('e', 64), repeat('f', 64), repeat('d', 64)]))->>'changed')::boolean,
  false, 'the same report again changes nothing');

select is(public.set_mirror_health('m-r', true), false, 'setting the health a mirror already has is no change');
select is(public.set_mirror_health('m-r', false), true, 'flipping it is a change');

select * from finish();
rollback;
