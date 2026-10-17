-- The uploader's name, kept with a safety rejection through an account
-- deletion (issue #146).
--
-- A safety rejection writes the uploader's hub display name and their sign in
-- username onto its public.asset_event row, at the moment of the decision.
-- Nothing else about the account is copied: no email, no provider id, no
-- avatar. An editorial rejection copies nothing. Closing the account still
-- works and still removes the account and its profile, and the two names stay
-- on the event.
--
-- The row ids are written out rather than looked up, for the reason
-- asset_rejection.test.sql gives: much of this runs as `authenticated`, where
-- asset_read_approved hides every row that is not approved.

begin;
select plan(19);

create extension if not exists pgtap with schema extensions;

-- An uploader shaped the way Discord leaves an account, with every key that
-- must not be copied filled in, an uploader with no provider names at all, a
-- moderator, and a plain account.
insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('e1000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.uploader@example.test',
    '{"full_name":"zyx_handle","name":"zyx_handle#0","email":"zyx.uploader@example.test","provider_id":"80351110224678912","sub":"80351110224678912","avatar_url":"https://cdn.discordapp.com/avatars/zyx.png","picture":"https://cdn.discordapp.com/avatars/zyx.png","custom_claims":{"global_name":"Zyx Global"}}'),
  ('e1000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.bare@example.test', '{}'),
  ('e1000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.moderator@example.test', '{"full_name":"zyx_moderator"}'),
  ('e1000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.plain@example.test', '{"full_name":"zyx_plain"}');

-- A hub name that differs from the Discord one, so the two columns can be told
-- apart.
update public.profile set display_name = 'Zyx Hub Name'
where user_id = 'e1000000-0000-0000-0000-000000000001';

insert into public.user_capability (user_id, capability)
values ('e1000000-0000-0000-0000-000000000003', 'can_moderate');

insert into public.asset (id, game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values
  ('0f8fad5b-0146-4000-8000-00000000000a', 'bar', 'armsolar', 'buildpic', 'src-146a', 'enc-146a', 'webp-lossless-256', 'unit/bar/armsolar/buildpic/enc-146a.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'e1000000-0000-0000-0000-000000000001'),
  ('0f8fad5b-0146-4000-8000-00000000000b', 'bar', 'armllt', 'buildpic', 'src-146b', 'enc-146b', 'webp-lossless-256', 'unit/bar/armllt/buildpic/enc-146b.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'e1000000-0000-0000-0000-000000000001'),
  ('0f8fad5b-0146-4000-8000-00000000000c', 'bar', 'armcom', 'buildpic', 'src-146c', 'enc-146c', 'webp-lossless-256', 'unit/bar/armcom/buildpic/enc-146c.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'e1000000-0000-0000-0000-000000000001'),
  ('0f8fad5b-0146-4000-8000-00000000000d', 'bar', 'armpw', 'buildpic', 'src-146d', 'enc-146d', 'webp-lossless-256', 'unit/bar/armpw/buildpic/enc-146d.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', 'e1000000-0000-0000-0000-000000000002'),
  ('0f8fad5b-0146-4000-8000-00000000000e', 'bar', 'armck', 'buildpic', 'src-146e', 'enc-146e', 'webp-lossless-256', 'unit/bar/armck/buildpic/enc-146e.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'bar_1.2.sdz', null);

-- ## The moderator deciding

set local role authenticated;
set local request.jwt.claims = '{"sub":"e1000000-0000-0000-0000-000000000003","role":"authenticated"}';

do $$
begin
  perform public.reject_asset('0f8fad5b-0146-4000-8000-00000000000a', 'safety');
  perform public.reject_asset('0f8fad5b-0146-4000-8000-00000000000b', 'editorial');
  perform public.approve_assets(ARRAY['0f8fad5b-0146-4000-8000-00000000000c'::uuid]);
  perform public.reject_asset('0f8fad5b-0146-4000-8000-00000000000d', 'safety');
end;
$$;

select lives_ok(
  $$select public.reject_asset('0f8fad5b-0146-4000-8000-00000000000e', 'safety')$$,
  'a picture whose uploader has already gone can still be rejected on safety grounds'
);

reset role;

-- ## What a safety rejection keeps

select is(
  (select uploader_display_name from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000a' and action = 'rejected'),
  'Zyx Hub Name',
  'a safety rejection keeps the uploader''s hub display name'
);

select is(
  (select uploader_provider_name from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000a' and action = 'rejected'),
  'zyx_handle',
  'and their Discord username'
);

select is(
  (select coalesce(uploader_display_name, 'none') || ' ' || coalesce(uploader_provider_name, 'none') from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000d' and action = 'rejected'),
  'zyx.bare none',
  'an account with no provider names keeps its hub name and no username'
);

select is(
  (select coalesce(uploader_display_name, 'none') || ' ' || coalesce(uploader_provider_name, 'none') from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000e' and action = 'rejected'),
  'none none',
  'a picture with no uploader has no names to keep'
);

-- ## What nothing else keeps

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000b'
      and (uploader_display_name is not null or uploader_provider_name is not null)),
  0,
  'an editorial rejection keeps no names'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000c'
      and (uploader_display_name is not null or uploader_provider_name is not null)),
  0,
  'nor does an approval'
);

select throws_ok(
  $$insert into public.asset_event (asset_id, action, rejection_kind, uploader, uploader_display_name)
    values ('0f8fad5b-0146-4000-8000-00000000000b', 'rejected', 'editorial', 'e1000000-0000-0000-0000-000000000001', 'Zyx Hub Name')$$,
  '23514',
  null,
  'the table refuses names on anything but a safety rejection, even from the table owner'
);

-- The names and the uuids the table already held, and no other column.
select columns_are(
  'public', 'asset_event',
  ARRAY['id', 'asset_id', 'action', 'rejection_kind', 'actor', 'uploader', 'at', 'uploader_display_name', 'uploader_provider_name'],
  'asset_event has no column for an email, a provider id, an avatar or an address'
);

select is(
  (select count(*)::int from public.asset_event e
    where e.asset_id = '0f8fad5b-0146-4000-8000-00000000000a'
      and (to_jsonb(e)::text like '%example.test%'
        or to_jsonb(e)::text like '%80351110224678912%'
        or to_jsonb(e)::text like '%cdn.discordapp.com%'
        or to_jsonb(e)::text like '%Zyx Global%')),
  0,
  'the safety rejection holds no email, provider id, avatar or other provider name'
);

-- ## Who can read it

set local role authenticated;
set local request.jwt.claims = '{"sub":"e1000000-0000-0000-0000-000000000004","role":"authenticated"}';

select throws_ok(
  $$select uploader_display_name, uploader_provider_name from public.asset_event$$,
  '42501',
  null,
  'a signed in account that is not a moderator cannot read the names'
);

reset role;
set local role anon;
set local request.jwt.claims = '';

select throws_ok(
  $$select uploader_display_name, uploader_provider_name from public.asset_event$$,
  '42501',
  null,
  'nor can anybody signed out'
);

reset role;

-- ## Closing the account

select lives_ok(
  $$delete from auth.users where id = 'e1000000-0000-0000-0000-000000000001'$$,
  'an account with a safety rejection can still be closed'
);

select is(
  (select count(*)::int from auth.users where id = 'e1000000-0000-0000-0000-000000000001'),
  0,
  'the account is gone'
);

select is(
  (select count(*)::int from public.profile where user_id = 'e1000000-0000-0000-0000-000000000001'),
  0,
  'and so is its profile'
);

select is(
  (select count(*)::int from public.asset
    where uploaded_by = 'e1000000-0000-0000-0000-000000000001'),
  0,
  'and nothing on public.asset names it any more'
);

select is(
  (select uploader_display_name || ' ' || uploader_provider_name from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000a' and action = 'rejected'),
  'Zyx Hub Name zyx_handle',
  'the safety rejection still names who it was'
);

select is(
  (select count(*)::int from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000b'
      and (uploader_display_name is not null or uploader_provider_name is not null)),
  0,
  'and the editorial rejection still names nobody'
);

-- The names were taken at the decision, so they are the names the account had
-- then. A rename afterwards does not rewrite the record.
update public.profile set display_name = 'Zyx Renamed'
where user_id = 'e1000000-0000-0000-0000-000000000002';

select is(
  (select uploader_display_name from public.asset_event
    where asset_id = '0f8fad5b-0146-4000-8000-00000000000d' and action = 'rejected'),
  'zyx.bare',
  'renaming the account later does not change the name on the record'
);

select * from finish();
rollback;
