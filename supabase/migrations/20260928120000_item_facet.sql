-- The gallery's filter chips (issue #459).
--
-- The chips used to come from the first thousand item rows in no order, so past
-- a thousand items a game or map could go missing from them. This view returns
-- each game key and each map name that a visible item carries, once, however
-- many items there are.
--
-- It is additive. Nothing existing is changed or dropped, so it can be applied
-- before the code that reads it ships.
--
-- Withdrawn items do not contribute. The row level security on public.item
-- already hides them from anon, but it shows an author their own withdrawn items
-- and a moderator all of them, so the filter is stated here too, the way
-- public.game_browse states it for its item count.
--
-- The game facet is game_key and not game_name, which can hold a version
-- carrying archive name unique to one row (issue #50). Null and empty values are
-- not facets.
create view public.item_facet
with (security_invoker = true) as
select 'game'::text as facet, i.game_key as value
from public.item as i
where i.deleted_at is null and i.game_key is not null and i.game_key <> ''
group by i.game_key
union all
select 'map'::text as facet, i.map_name as value
from public.item as i
where i.deleted_at is null and i.map_name is not null and i.map_name <> ''
group by i.map_name;

-- Whatever these roles hold is taken away first, so a privilege a hosted default
-- handed out does not sit under the grant below.
revoke all on public.item_facet from anon, authenticated, service_role;

-- Select only, for the roles that already select from public.item. The view is
-- computed, so a write to it is not something to allow.
grant select on public.item_facet to anon, authenticated, service_role;
