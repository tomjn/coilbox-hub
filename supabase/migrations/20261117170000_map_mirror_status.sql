-- What the hub mirror system holds for one map, for its edit page.
--
-- Security definer because archive, archive_mirror and mirror are closed to
-- every browser role. It answers only someone public.can_edit_map lets edit
-- the map, and null for anybody else.
--
-- Every upload of the map's archive except failed ones, newest first. Failed
-- uploads never reached a mirror and freed their file name, so there is
-- nothing to say about them. Each upload lists the mirrors that last reported
-- holding it, with whether the hub links that mirror today.

create function public.map_mirror_status(p_map_id uuid) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select case when public.can_edit_map(p_map_id) then (
    select coalesce(jsonb_agg(jsonb_build_object(
      'filename', a.filename,
      'state', a.state,
      'created_at', a.created_at,
      'listed_at', a.listed_at,
      'removed_at', a.removed_at,
      'removal_reason', a.removal_reason,
      'mine', a.uploader is not distinct from auth.uid(),
      'mirrors', (
        select coalesce(jsonb_agg(jsonb_build_object(
          'name', m.name,
          'linked', m.enabled and m.healthy,
          'last_seen_at', am.last_seen_at
        ) order by m.id), '[]'::jsonb)
        from public.archive_mirror as am
        join public.mirror as m on m.id = am.mirror_id
        where am.archive_id = a.id
      )
    ) order by a.created_at desc, a.id), '[]'::jsonb)
    from public.archive as a
    join public.map as map on map.id = p_map_id
    where a.kind = 'map'
      and a.internal_name = map.map_name
      and a.state <> 'failed'
  ) end;
$$;

revoke execute on function public.map_mirror_status(uuid) from public;
grant execute on function public.map_mirror_status(uuid) to authenticated, service_role;
