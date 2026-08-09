-- Warpath and conquest are both `kind: challenge` and differ only by a `mode`
-- inside the payload, so a listing showed them as the same thing. They are not
-- the same thing to a player, and telling them apart is the whole point of a
-- browsable gallery.
--
-- A stored generated column rather than reading the JSON in the query. The
-- listing avoids selecting `container` because it is by far the largest column,
-- and pulling one value out of it per row on every page would undo exactly that.
-- Computed once at write time, and nothing can set it out of step with the
-- payload it came from.
alter table public.item
  add column mode text generated always as (container -> 'payload' ->> 'mode') stored;

-- Null for every kind that has no mode, so this only narrows challenges.
create index item_live_mode_idx on public.item (mode, created_at desc)
  where deleted_at is null and mode is not null;
