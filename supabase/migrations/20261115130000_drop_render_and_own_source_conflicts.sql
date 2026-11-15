-- Remove source disagreements the upload route no longer records.
--
-- The route used to record one whenever an upload replaced a row with
-- different bytes from the same archive, including the uploader replacing
-- their own picture. A client update that changed how renders are drawn then
-- flagged every render of every game it re-sent. On 2026-11-15 production had
-- 2,520 open rows, every one a render and 2,515 of them the uploader's own.
--
-- The route now skips renders, which the client draws, and the uploader's own
-- re-uploads. These rows are deleted rather than marked as looked at, because
-- that needs a moderator to name as the actor and a migration has none.
-- Nothing writes them again, so the unique index has nothing to guard.
delete from public.asset_source_conflict as c
using public.asset as a
where a.id = c.asset_id
  and (
    (a.unit_name is not null and a.variant like 'render:%')
    or c.reported_by = a.uploaded_by
  );
