-- The two things a height overlay needs that no other asset does, and the map
-- half of the variant vocabulary (issue #105).
--
-- coilbox extracts height as a 16 bit grayscale ramp with a linear mapping, so
-- a sample is a fraction of a range rather than a height. The two ends of that
-- range are in the map archive and nowhere else: nothing downstream of
-- extraction can recover them, and without them the layer is a picture of a
-- heightmap rather than a heightmap. Same reasoning as map_width and
-- map_height above them, and they are stored the same way, mandatory where they
-- mean something and refused where they do not.
--
-- Floats rather than integers, unlike every other measurement on this table.
-- Terrain below sea level is a negative height, the archive stores both ends as
-- floats, and rounding either end moves every sample in the layer.

alter table public.asset
  add column world_height_min real,
  add column world_height_max real;

comment on column public.asset.world_height_min is
  'The elmo height a height overlay''s darkest sample means. Null on everything else.';
comment on column public.asset.world_height_max is
  'The elmo height a height overlay''s brightest sample means. Null on everything else.';

alter table public.asset
  add constraint asset_height_range_check check (
    num_nonnulls(world_height_min, world_height_max)
      = case when variant = 'overlay:height' then 2 else 0 end
  );

-- A flat map has one height, so the two ends may meet. They may not cross: a
-- reversed range reads every sample upside down and nothing about the result
-- looks wrong.
alter table public.asset
  add constraint asset_height_range_order_check check (
    world_height_max >= world_height_min
  );

-- The map side of the variant vocabulary. The table left this open because the
-- issues that name the overlay layers had not landed when it was written, and
-- #105 names all four, so the check arrives with them. Widening it later is one
-- line, the same as item_kind_check and as the unit side.
--
-- Worth having even though the upload route refuses the same list, because the
-- route is not the only writer: the seed (#110) writes rows straight to the
-- durable tier, and a seed that says 'metal' where an upload says
-- 'overlay:metal' produces two vocabularies and a picture that resolves for one
-- of them.
alter table public.asset
  add constraint asset_map_variant_check check (
    map_name is null
      or variant in ('minimap', 'overlay:metal', 'overlay:type', 'overlay:height')
  );
