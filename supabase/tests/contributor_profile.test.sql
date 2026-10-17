-- What a contributor's public profile may count (issue #293).
--
-- The profile page reads map.submitted_by, asset.uploaded_by, game.submitted_by
-- and item.author_id as the anonymous role, with no function in between. So
-- the only thing keeping a withdrawn item, a queued picture or a hidden game
-- out of someone's totals is row level security. These cases run the page's
-- reads as anon and count what comes back.

begin;
select plan(8);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email, raw_user_meta_data)
values
  ('c0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'zyx.contrib@example.test', '{"full_name":"Zyx Contributor"}');

-- One approved picture, one queued, one rejected, all uploaded by the account.
insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, moderation, approval_source, uploaded_by)
values
  ('zyxgame', 'zyxunit', 'buildpic', 'zyx-src-a', 'zyx-enc-approved', 'webp-lossless-256', 'unit/zyxgame/zyxunit/buildpic/zyx-enc-approved.webp', 'uploaded', 'image/webp', 4096, 128, 128, 'zyx_1.sdz', 'approved', 'seed', 'c0000000-0000-0000-0000-000000000001');

insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, uploaded_by)
values
  ('zyxgame', 'zyxunit', 'render:front', 'zyx-src-p', 'zyx-enc-pending', 'webp-q80-256', 'unit/zyxgame/zyxunit/render-front/zyx-enc-pending.webp', 'uploaded', 'image/webp', 8192, 256, 256, 'zyx_1.sdz', 'c0000000-0000-0000-0000-000000000001');

insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive, moderation, rejection_kind, uploaded_by)
values
  ('Zyx Map 1.0', 'minimap', 'zyx-src-r', 'zyx-enc-rejected', 'webp-q80-512', 'map/zyx/minimap/zyx-enc-rejected.webp', 'uploaded', 'image/webp', 40000, 512, 512, 8192, 8192, 'zyx_map_1.0.sd7', 'rejected', 'editorial', 'c0000000-0000-0000-0000-000000000001');

-- One live item and one the author withdrew.
insert into public.item (id, kind, kind_version, title, container, author_id, author_name)
values
  ('c1000000-0000-0000-0000-000000000001', 'preset', 1, 'Zyx live', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', 'c0000000-0000-0000-0000-000000000001', 'Zyx Contributor'),
  ('c1000000-0000-0000-0000-000000000002', 'preset', 1, 'Zyx withdrawn', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', 'c0000000-0000-0000-0000-000000000001', 'Zyx Contributor');

update public.item set deleted_at = now() where id = 'c1000000-0000-0000-0000-000000000002';

-- One visible game and one a moderator hid.
insert into public.game (id, shortname, display_name, submitted_by)
values
  ('c2000000-0000-4000-8000-000000000001', 'ZYXA', 'Zyx Visible', 'c0000000-0000-0000-0000-000000000001'),
  ('c2000000-0000-4000-8000-000000000002', 'ZYXB', 'Zyx Hidden', 'c0000000-0000-0000-0000-000000000001');

update public.game set hidden_at = now() where id = 'c2000000-0000-4000-8000-000000000002';

-- Two maps. Row level security shows both. Which of them has a page is the
-- licence gate, which only the secret key can read, so the app applies it.
insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest, submitted_by)
values
  ('Zyx Map 1.0', 'zyx-map-1-0', 4096, 4096, 0, 320, 'zyx-map-src-1', 'zyx_map_1.0.sd7', 1, 'zyx-digest-1', 'c0000000-0000-0000-0000-000000000001'),
  ('Zyx Map 2.0', 'zyx-map-2-0', 4096, 4096, 0, 320, 'zyx-map-src-2', 'zyx_map_2.0.sd7', 1, 'zyx-digest-2', 'c0000000-0000-0000-0000-000000000001');

-- The page's reads, as a visitor with no session.
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select display_name from public.profile where user_id = 'c0000000-0000-0000-0000-000000000001'),
  'Zyx Contributor',
  'a visitor can read the name the profile is headed with'
);

select is(
  (select count(*) from public.asset where uploaded_by = 'c0000000-0000-0000-0000-000000000001')::int,
  1,
  'pictures counted by uploader are the approved one, not the queued or rejected ones'
);

select is(
  (select count(*) from public.asset
    where uploaded_by = 'c0000000-0000-0000-0000-000000000001'
      and hash in ('zyx-enc-pending', 'zyx-enc-rejected'))::int,
  0,
  'asking for the queued and rejected pictures by name returns neither'
);

select is(
  (select count(*) from public.item where author_id = 'c0000000-0000-0000-0000-000000000001')::int,
  1,
  'items counted by author are the live one, not the withdrawn one'
);

select is(
  (select title from public.item where author_id = 'c0000000-0000-0000-0000-000000000001'),
  'Zyx live',
  'and the item that remains is the live one'
);

select is(
  (select count(*) from public.game where submitted_by = 'c0000000-0000-0000-0000-000000000001')::int,
  1,
  'games counted by submitter leave out the hidden game'
);

select is(
  (select shortname from public.game where submitted_by = 'c0000000-0000-0000-0000-000000000001'),
  'ZYXA',
  'and the game that remains is the visible one'
);

select is(
  (select count(*) from public.map where submitted_by = 'c0000000-0000-0000-0000-000000000001')::int,
  2,
  'maps counted by submitter are all readable, so the app must apply the licence gate'
);

select * from finish();
rollback;
