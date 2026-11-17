-- Adds `page`, the hub page of the map or game an upload is, to the rows the
-- uploads and moderation pages show. Null when the hub has no such page yet.
create or replace function public.archive_moderation_row(p_archive public.archive) returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select jsonb_build_object(
    'id', p_archive.id,
    'sha256', p_archive.sha256,
    'filename', p_archive.filename,
    'kind', p_archive.kind,
    'internal_name', p_archive.internal_name,
    'version', p_archive.version,
    'shortname', p_archive.shortname,
    'author', p_archive.author,
    'size', p_archive.size,
    'listed_at', p_archive.listed_at,
    'page', case p_archive.kind
      when 'map' then (
        select '/map/' || public.url_path_segment(mp.slug)
        from public.map as mp
        where mp.map_name = p_archive.internal_name
      )
      else (
        select '/games/' || public.url_path_segment(g.shortname)
        from public.game as g
        where g.id = p_archive.game_id
      )
    end,
    'copies', coalesce((
      select jsonb_agg(
        m.base_url || '/' || p_archive.kind || 's/' || public.url_path_segment(p_archive.filename)
        order by m.id
      )
      from public.archive_mirror as am
      join public.mirror as m on m.id = am.mirror_id
      where am.archive_id = p_archive.id and m.enabled and m.healthy
    ), '[]'::jsonb)
  )
$$;
