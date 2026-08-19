-- public.asset carries two differently shaped identities in one table
-- (issue #100), and the rules that keep them apart are constraints rather than
-- code. Nothing in TypeScript can enforce them, and the failure they prevent -
-- a row in neither keyspace, or in both, or a map with no world size - is not
-- an error anywhere, it is a picture that quietly never resolves or an overlay
-- that is subtly misaligned. So they are asserted here.
--
-- Grants and policies are not tested here. #102 owns those and adds them to
-- item_rls.test.sql and table_privileges.test.sql alongside the existing ones.

begin;
select plan(42);

create extension if not exists pgtap with schema extensions;

-- Somebody to have uploaded one, so the account deletion behaviour has a real
-- foreign key to exercise rather than a null.
insert into auth.users (id, instance_id, aud, role, email)
values ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'seed@example.test');

-- The happy paths first, so a later failure can be told apart from the table
-- refusing everything.

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'unit/bar/armsolar/buildpic/enc-a.webp', 'extracted', 'image/webp', 900, 128, 128, 'byar-1.2.3.sdd')$$,
  'a unit asset is keyed on its game, its unit and its variant'
);

select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.8', 'minimap', 'src-m', 'enc-m', 'webp-q80-512', 'map/comet/minimap/enc-m.webp', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'comet_catcher_remake_1.8.sd7')$$,
  'a map asset is keyed on the full canonical map name and its variant'
);

-- The check the partial indexes cannot do. A row filling in both names collides
-- with nothing in either index, so without this it stores happily and belongs to
-- neither keyspace.
select throws_ok(
  $$insert into public.asset (game, unit_name, map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('bar', 'armsolar', 'Comet Catcher Remake 1.8', 'buildpic', 'src-b', 'enc-b', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 8192, 8192, 'a.sdd')$$,
  '23514',
  null,
  'a row cannot be both a unit asset and a map asset'
);

select throws_ok(
  $$insert into public.asset (variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('buildpic', 'src-c', 'enc-c', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 'a.sdd')$$,
  '23514',
  null,
  'a row must be one or the other, not neither'
);

-- An empty string is not a name. It satisfies num_nonnulls, so without the
-- length checks it would mint an identity nothing can ever look up.
select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', '   ', 'buildpic', 'src-d', 'enc-d', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 'a.sdd')$$,
  '23514',
  null,
  'a blank unit name is refused rather than stored as an identity'
);

-- game is half the unit key, so a unit asset without one is not addressable.
select throws_ok(
  $$insert into public.asset (unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('armsolar', 'buildpic', 'src-e', 'enc-e', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 'a.sdd')$$,
  '23514',
  null,
  'a unit asset without a game is refused'
);

-- The same map archive is used by BAR, XTA and BA, so scoping one to a game
-- would mean three copies of one picture.
select throws_ok(
  $$insert into public.asset (game, map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('bar', 'Comet Catcher Remake 1.8', 'overlay:metal', 'src-f', 'enc-f', 'webp-lossless-source', 'p', 'extracted', 'image/webp', 900, 512, 512, 8192, 8192, 'a.sd7')$$,
  '23514',
  null,
  'a map asset cannot be scoped to one game'
);

-- Identity, both halves of it. One set per game, replaced rather than added to.
select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armsolar', 'buildpic', 'src-g', 'enc-g', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 'byar-1.2.4.sdd')$$,
  '23505',
  null,
  'a second buildpic for the same unit in the same game is refused'
);

-- The reason game is in the key at all: three games have a solar collector and
-- the pictures differ.
select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('xta', 'armsolar', 'buildpic', 'src-h', 'enc-h', 'webp-lossless-256', 'unit/xta/armsolar/buildpic/enc-h.webp', 'extracted', 'image/webp', 900, 128, 128, 'xta.sdz')$$,
  'the same unit name in another game is a different asset'
);

select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armsolar', 'render:315', 'src-i', 'enc-i', 'webp-q80-256', 'unit/bar/armsolar/render-315/enc-i.webp', 'rendered', 'image/webp', 2000, 256, 192, 'byar-1.2.3.sdd')$$,
  'a render of a unit that already has a buildpic is a different variant'
);

select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.8', 'minimap', 'src-j', 'enc-j', 'webp-q80-512', 'p', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'a.sd7')$$,
  '23505',
  null,
  'a second minimap for the same map is refused'
);

-- The version string stays in the name. A remade map has different terrain, so
-- it is a different map and gets its own row rather than replacing the old one.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.9', 'minimap', 'src-k', 'enc-k', 'webp-q80-512', 'map/comet19/minimap/enc-k.webp', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'comet_catcher_remake_1.9.sd7')$$,
  'a later revision of a map is its own row, not a replacement'
);

-- The map side of the variant vocabulary, which #105 closes now that the caps
-- name all four of them.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.8', 'overlay:metal', 'src-l', 'enc-l', 'webp-lossless-source', 'maps/overlay/metal/enc-l.webp', 'extracted', 'image/webp', 9000, 512, 512, 8192, 8192, 'comet_catcher_remake_1.8.sd7')$$,
  'a map takes the minimap and the three overlay layers'
);

-- Two writers, one vocabulary. The route refuses this list too, but the seed
-- (#110) writes rows without going near the route, and 'metal' where the rest
-- of the hub says 'overlay:metal' is a picture that resolves for nobody.
select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Comet Catcher Remake 1.8', 'metal', 'src-l2', 'enc-l2', 'webp-lossless-source', 'p', 'extracted', 'image/webp', 9000, 512, 512, 8192, 8192, 'a.sd7')$$,
  '23514',
  null,
  'a map variant outside the vocabulary is refused'
);

-- The height overlay is a linear ramp between two world heights, and only the
-- archive has them. A row without them stores a picture of a heightmap.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, world_height_min, world_height_max, source_archive)
    values ('Comet Catcher Remake 1.8', 'overlay:height', 'src-l3', 'enc-l3', 'webp-lossless-512', 'maps/overlay/height/enc-l3.webp', 'extracted', 'image/webp', 90000, 513, 513, 8192, 8192, -40.5, 320.25, 'comet_catcher_remake_1.8.sd7')$$,
  'a height overlay records the elmo range its ramp spans'
);

select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('Tangerine 1.1', 'overlay:height', 'src-l4', 'enc-l4', 'webp-lossless-512', 'maps/overlay/height/enc-l4.webp', 'extracted', 'image/webp', 90000, 513, 513, 8192, 8192, 'a.sd7')$$,
  '23514',
  null,
  'a height overlay without its world range is refused'
);

select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, world_height_min, world_height_max, source_archive)
    values ('Tangerine 1.1', 'minimap', 'src-l5', 'enc-l5', 'webp-q80-512', 'p', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 0, 320, 'a.sd7')$$,
  '23514',
  null,
  'a world range on anything but a height overlay is refused'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, world_height_min, world_height_max, source_archive)
    values ('bar', 'armllt', 'buildpic', 'src-l6', 'enc-l6', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 0, 320, 'a.sdd')$$,
  '23514',
  null,
  'a unit asset carrying a world range is refused'
);

-- A flat map has one height, so the ends may meet. A reversed range reads every
-- sample upside down and nothing about the result looks wrong.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, world_height_min, world_height_max, source_archive)
    values ('Flatland 1.0', 'overlay:height', 'src-l7', 'enc-l7', 'webp-lossless-512', 'maps/overlay/height/enc-l7.webp', 'extracted', 'image/webp', 9000, 129, 129, 1024, 1024, 100, 100, 'flatland_1.0.sd7')$$,
  'a flat map may have a range of no width'
);

select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, world_height_min, world_height_max, source_archive)
    values ('Tangerine 1.1', 'overlay:height', 'src-l8', 'enc-l8', 'webp-lossless-512', 'maps/overlay/height/enc-l8.webp', 'extracted', 'image/webp', 9000, 129, 129, 1024, 1024, 320, 0, 'a.sd7')$$,
  '23514',
  null,
  'a reversed world range is refused'
);

-- map_width and map_height are the map in world units, not the texture in
-- pixels. Only the archive has them, so a map row that omits them can never be
-- repaired from anything downstream.
select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, source_archive)
    values ('Tangerine 1.1', 'minimap', 'src-n', 'enc-n', 'webp-q80-512', 'p', 'extracted', 'image/webp', 40000, 512, 512, 8192, 'a.sd7')$$,
  '23514',
  null,
  'a map asset with only one of its two world dimensions is refused'
);

select throws_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('Tangerine 1.1', 'minimap', 'src-o', 'enc-o', 'webp-q80-512', 'p', 'extracted', 'image/webp', 40000, 512, 512, 'a.sd7')$$,
  '23514',
  null,
  'a map asset with no world size at all is refused'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive)
    values ('bar', 'armpw', 'buildpic', 'src-p', 'enc-p', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 8192, 8192, 'a.sdd')$$,
  '23514',
  null,
  'a unit asset has no world size, so carrying one is a mistake worth refusing'
);

-- The unit variant vocabulary. A typo here would mint an identity that nothing
-- ever asks for, and the symptom is a missing picture rather than an error.
select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armpw', 'render:0', 'src-q', 'enc-q', 'webp-q80-256', 'unit/bar/armpw/render-0/enc-q.webp', 'rendered', 'image/webp', 2000, 256, 256, 'a.sdd')$$,
  'a render at any angle is accepted'
);

select throws_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'armpw', 'buildpick', 'src-r', 'enc-r', 'webp-lossless-256', 'p', 'extracted', 'image/webp', 900, 128, 128, 'a.sdd')$$,
  '23514',
  null,
  'a unit variant outside the vocabulary is refused'
);

-- Two units can legitimately share a picture, so neither hash is unique. A
-- unique index on either would refuse the second one.
select lives_ok(
  $$insert into public.asset (game, unit_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive)
    values ('bar', 'corsolar', 'buildpic', 'src-a', 'enc-a', 'webp-lossless-256', 'unit/bar/corsolar/buildpic/enc-a.webp', 'extracted', 'image/webp', 900, 128, 128, 'byar-1.2.3.sdd')$$,
  'two units may share both hashes, because they may share a picture'
);

-- Defaults. Everything arrives unapproved and in the staging tier, and the seed
-- and the promotion job are what move it.
select is(
  (select tier from public.asset where hash = 'enc-m'), 'blob',
  'an asset starts in the staging tier'
);

select is(
  (select moderation from public.asset where hash = 'enc-m'), 'pending',
  'an asset starts unapproved'
);

select throws_ok(
  $$update public.asset set tier = 'cdn' where hash = 'enc-m'$$,
  '23514',
  null,
  'a tier that is not one of the two stores is refused'
);

select throws_ok(
  $$update public.asset set moderation = 'maybe' where hash = 'enc-m'$$,
  '23514',
  null,
  'a moderation state outside the three is refused'
);

-- The two vocabularies #117 settles, and the one relationship it draws between
-- moderation and approval_source.

select throws_ok(
  $$update public.asset set origin = 'scraped' where hash = 'enc-m'$$,
  '23514',
  null,
  'an origin outside the vocabulary is refused'
);

select throws_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'vibes' where hash = 'enc-m'$$,
  '23514',
  null,
  'an approval source outside the vocabulary is refused'
);

-- Otherwise the audit trail has a hole in exactly the rows being served.
select throws_ok(
  $$update public.asset set moderation = 'approved' where hash = 'enc-m'$$,
  '23514',
  null,
  'an approved row has to say what approved it'
);

-- And otherwise "approval_source is not null" stops meaning approved.
select throws_ok(
  $$update public.asset set approval_source = 'moderator' where hash = 'enc-m'$$,
  '23514',
  null,
  'a pending row cannot claim an approval source'
);

select lives_ok(
  $$update public.asset set moderation = 'approved', approval_source = 'moderator' where hash = 'enc-m'$$,
  'approving in the grid records that a moderator did it'
);

-- A safety rejection over something a moderator had already approved is a case
-- #115 has to be able to demonstrate afterwards, so rejecting must not have to
-- destroy the record of who approved it first.
select lives_ok(
  $$update public.asset set moderation = 'rejected', rejection_kind = 'safety'
    where hash = 'enc-m'$$,
  'an approved row can be rejected later'
);

select is(
  (select approval_source from public.asset where hash = 'enc-m'), 'moderator',
  'and still says who had approved it'
);

select lives_ok(
  $$update public.asset set moderation = 'rejected', rejection_kind = 'editorial'
    where hash = 'enc-q'$$,
  'a row rejected straight out of the queue was never approved by anything'
);

-- The shape the seed actually writes: live on arrival, and saying so.
select lives_ok(
  $$insert into public.asset (map_name, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, map_width, map_height, source_archive, tier, moderation, approval_source)
    values ('Tangerine 1.1', 'minimap', 'src-s', 'enc-s', 'webp-q80-512', 'map/tangerine/minimap/enc-s.webp', 'extracted', 'image/webp', 40000, 512, 512, 8192, 8192, 'tangerine_1.1.sd7', 'static', 'approved', 'seed')$$,
  'the seed writes an approved row to the durable tier in one statement'
);

select has_trigger('public', 'asset', 'asset_touch_updated_at',
  'updated_at is maintained by the table, not trusted from whoever wrote the row');

-- Closing an account must not take approved pictures the gallery is serving with
-- it, unlike an item, which is the author's own work and cascades.
update public.asset
  set uploaded_by = '33333333-3333-3333-3333-333333333333'
  where hash = 'enc-m';

delete from auth.users where id = '33333333-3333-3333-3333-333333333333';

select is(
  (select count(*) from public.asset where hash = 'enc-m')::int, 1,
  'deleting the uploader leaves the asset in place'
);

select is(
  (select uploaded_by from public.asset where hash = 'enc-m'), null,
  'and forgets who uploaded it'
);

select * from finish();
rollback;
