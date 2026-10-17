-- How many units a mod project adds (issue #415).
--
-- A project's card shows the number of clones, and used to get it by reading
-- every clone's whole unit definition out of `container` and counting them in
-- the app. This returns the number instead, so a card reads one integer.
--
-- It is a function over the row rather than a generated column. A stored
-- generated column would rewrite public.item, whose rows carry containers of up
-- to 512 KB, and a generated expression cannot hold the subquery that counts
-- keys. As a function that takes the item row, PostgREST serves it as a
-- computed column: `select=id,clone_count` on public.item.
--
-- It runs as the caller (the default), so it only ever sees rows that the
-- caller's row level security on public.item already lets through. A withdrawn
-- item stays hidden from anon exactly as before.
--
-- The count matches `parseClones` in lib/gallery/modProjectPreview.ts: keys are
-- trimmed and lower cased, a blank key counts for nothing, two keys that
-- normalise alike count once, and a value that is not an object counts for
-- nothing. The one difference is that the app trims all Unicode whitespace and
-- this trims ASCII whitespace only. Real unit keys are plain lower case names.
create function public.clone_count(item public.item) returns integer
language sql
stable
set search_path = ''
as $$
  select count(distinct key)::integer
  from (
    select lower(btrim(entry.key, E' \t\n\r\f\v')) as key
    from jsonb_each(
      case
        when jsonb_typeof(item.container #> '{payload,edits,clones}') = 'object'
          then item.container #> '{payload,edits,clones}'
        else '{}'::jsonb
      end
    ) as entry
    where jsonb_typeof(entry.value) = 'object'
  ) as keys
  where key <> '';
$$;

-- Execute is granted to PUBLIC on every new function, so this revoke is the
-- line that makes the grants below the whole list.
revoke execute on function public.clone_count(public.item) from public;
grant execute on function public.clone_count(public.item) to anon, authenticated, service_role;
