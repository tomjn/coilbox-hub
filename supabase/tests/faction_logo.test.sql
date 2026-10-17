-- Faction logos as a third kind of asset (issue #238).
--
-- Three things only the database can prove. A faction logo has its own identity
-- and the table refuses the shapes that would put one in the wrong keyspace.
-- Promotion writes the durable path onto the game's side in the same statement
-- that moves the row. And the logo follows the moderation decision afterwards:
-- a rejection takes it off, a returned and re-approved picture puts it back, and
-- a side re-inserted by a facts resubmission gets it again.

begin;
select plan(26);

create extension if not exists pgtap with schema extensions;

insert into public.game (id, shortname)
values
  ('0f8fad5b-3800-4000-8000-000000000001', 'FLG'),
  ('0f8fad5b-3800-4000-8000-000000000002', 'FLH');

insert into public.game_faction (game_id, key, name)
values
  ('0f8fad5b-3800-4000-8000-000000000001', 'armada', 'Armada'),
  ('0f8fad5b-3800-4000-8000-000000000001', 'cortex', 'Cortex'),
  ('0f8fad5b-3800-4000-8000-000000000002', 'armada', 'Armada');

-- A logo a maintainer seeded by hand, which is what every logo was before this.
update public.game_faction
set logo_path = 'factions/cortex.webp', logo_hash = 'hand'
where key = 'cortex';

-- ## Identity

select lives_ok(
  $$insert into public.asset (id, game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, moderation, approval_source)
    values ('0f8fad5b-3800-4000-8000-00000000000a', 'FLG', 'armada', 'logo', 'src-a', 'enc-a', 'webp-lossless-128', 'factions/FLG/logo/enc-a.webp', 'extracted', 'image/webp', 3000, 64, 64, 'flg_1.sdz', 'approved', 'moderator')$$,
  'a faction logo is keyed on its game, its faction and its variant'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('FLG', 'armcom', 'cortex', 'logo', 'src-b', 'enc-b', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23514',
  null,
  'a row cannot be both a unit asset and a faction logo'
);

select throws_ok(
  $$insert into public.asset (map_name, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.8', 'cortex', 'minimap', 'src-c', 'enc-c', 'webp-q80-512', 'p', 'extracted', 'image/webp', 3000, 64, 64, 8192, 8192, 'a.sd7')$$,
  '23514',
  null,
  'nor both a map asset and a faction logo'
);

select throws_ok(
  $$insert into public.asset (faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('cortex', 'logo', 'src-d', 'enc-d', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23514',
  null,
  'a faction logo without a game is refused, since faction keys repeat across games'
);

select throws_ok(
  $$insert into public.asset (game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('FLG', 'cortex', 'buildpic', 'src-e', 'enc-e', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23514',
  null,
  'a faction has one variant and it is the logo'
);

select throws_ok(
  $$insert into public.asset (game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('FLG', '   ', 'logo', 'src-f', 'enc-f', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23514',
  null,
  'a blank faction key is refused rather than stored as an identity'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('FLG', 'armcom', 'logo', 'src-g', 'enc-g', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23514',
  null,
  'the logo variant does not leak onto a unit'
);

select throws_ok(
  $$insert into public.asset (game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('FLG', 'armada', 'logo', 'src-h', 'enc-h', 'webp-lossless-128', 'p', 'extracted', 'image/webp', 3000, 64, 64, 'a.sdz')$$,
  '23505',
  null,
  'a second logo for the same faction of the same game is refused'
);

select lives_ok(
  $$insert into public.asset (id, game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, tier, moderation, approval_source)
    values ('0f8fad5b-3800-4000-8000-00000000000c', 'FLH', 'armada', 'logo', 'src-i', 'enc-c', 'webp-lossless-128', 'factions/FLH/logo/enc-c.webp', 'extracted', 'image/webp', 3000, 64, 64, 'flh_1.sdz', 'static', 'approved', 'seed')$$,
  'the same faction key in another game is a different logo'
);

-- ## Promotion

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada'),
  null,
  'an approved logo still in staging shows nowhere'
);

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000002' and key = 'armada'),
  'factions/FLH/logo/enc-c.webp',
  'a logo written straight to the durable tier by the seed is shown at once'
);

insert into public.asset (id, game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
values ('0f8fad5b-3800-4000-8000-00000000000b', 'FLG', 'cortex', 'logo', 'src-j', 'enc-j', 'webp-lossless-128', 'factions/FLG/logo/enc-j.webp', 'uploaded', 'image/webp', 3000, 64, 64, 'flg_1.sdz');

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'cortex'),
  'factions/cortex.webp',
  'a pending upload leaves the hand seeded logo where it was'
);

reset role;
set local role service_role;
set local request.jwt.claims = '';

select is(
  (select count(*)::int from public.promote_assets(
    ARRAY['0f8fad5b-3800-4000-8000-00000000000a'::uuid],
    ARRAY['factions/FLG/logo/enc-a.webp'])),
  1,
  'promotion moves the faction logo like any other picture'
);

reset role;

select is(
  (select logo_path || ' ' || logo_hash from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada'),
  'factions/FLG/logo/enc-a.webp enc-a',
  'and writes its durable path and hash onto the game''s side'
);

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000002' and key = 'armada'),
  'factions/FLH/logo/enc-c.webp',
  'the side with the same key in another game is not touched by it'
);

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'cortex'),
  'factions/cortex.webp',
  'nor is another side of the same game'
);

-- Approving the pending upload and promoting it replaces the seeded logo, which
-- is the point of the promotion target.
update public.asset set moderation = 'approved', approval_source = 'moderator'
where id = '0f8fad5b-3800-4000-8000-00000000000b';

set local role service_role;
select is(
  (select count(*)::int from public.promote_assets(
    ARRAY['0f8fad5b-3800-4000-8000-00000000000b'::uuid],
    ARRAY['factions/FLG/logo/enc-j.webp'])),
  1,
  'the approved upload is promoted'
);
reset role;

select is(
  (select logo_path || ' ' || logo_hash from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'cortex'),
  'factions/FLG/logo/enc-j.webp enc-j',
  'a promoted logo replaces the one a maintainer seeded by hand'
);

-- ## A facts resubmission replaces the sides and must not lose the logos

delete from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001';
insert into public.game_faction (game_id, key, name)
values
  ('0f8fad5b-3800-4000-8000-000000000001', 'armada', 'Armada'),
  ('0f8fad5b-3800-4000-8000-000000000001', 'cortex', 'Cortex'),
  ('0f8fad5b-3800-4000-8000-000000000001', 'legion', 'Legion');

select is(
  (select string_agg(key || '=' || coalesce(logo_path, 'none'), ',' order by key)
   from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001'),
  'armada=factions/FLG/logo/enc-a.webp,cortex=factions/FLG/logo/enc-j.webp,legion=none',
  'a side inserted again takes the live logo for its key, and one with no logo stays without'
);

-- ## The logo follows the moderation decision

update public.asset
set moderation = 'rejected', rejection_kind = 'editorial'
where id = '0f8fad5b-3800-4000-8000-00000000000a';

select is(
  (select coalesce(logo_path, 'none') || ' ' || coalesce(logo_hash, 'none') from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada'),
  'none none',
  'rejecting the logo that is showing takes it off the side'
);

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'cortex'),
  'factions/FLG/logo/enc-j.webp',
  'and leaves every other side alone'
);

update public.asset
set moderation = 'pending', rejection_kind = null, approval_source = null
where id = '0f8fad5b-3800-4000-8000-00000000000a';

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada'),
  null,
  'a logo returned to the queue is not shown while it waits'
);

update public.asset
set moderation = 'approved', approval_source = 'moderator'
where id = '0f8fad5b-3800-4000-8000-00000000000a';

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada'),
  'factions/FLG/logo/enc-a.webp',
  'and is shown again once it is approved, though promotion has no reason to visit it twice'
);

-- A newer archive replaces the row: back to pending on the staging tier. The
-- approved bytes keep showing, and rejecting the replacement does not take them
-- down, because it is the replacement's bytes that were refused.
update public.asset
set tier = 'bucket', path = 'factions/FLH/logo/enc-c2.webp', hash = 'enc-c2', moderation = 'pending', approval_source = null
where id = '0f8fad5b-3800-4000-8000-00000000000c';

update public.asset
set moderation = 'rejected', rejection_kind = 'editorial'
where id = '0f8fad5b-3800-4000-8000-00000000000c';

select is(
  (select logo_path from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000002' and key = 'armada'),
  'factions/FLH/logo/enc-c.webp',
  'rejecting a replacement leaves the approved logo it was replacing'
);

-- Safety is final and the takedown queue still gets its row, so the trigger
-- here is on top of that and not instead of it.
update public.asset
set moderation = 'rejected', rejection_kind = 'safety'
where id = '0f8fad5b-3800-4000-8000-00000000000a';

select is(
  (select count(*)::int from public.game_faction where game_id = '0f8fad5b-3800-4000-8000-000000000001' and key = 'armada' and logo_path is not null),
  0,
  'a safety rejection of the logo that is showing takes it off the side'
);

select is(
  (select count(*)::int from public.asset_withdrawal where asset_id = '0f8fad5b-3800-4000-8000-00000000000a'),
  1,
  'and the file is still queued for takedown from the durable tier'
);

select * from finish();
rollback;
