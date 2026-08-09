-- Filters answer "show me presets for this map". Search answers "show me that
-- thing someone mentioned in chat last week", which is the more common way people
-- arrive.
--
-- Title and description only. The payload is not written for humans to read, so
-- searching inside it would match on field names and identifiers and bury the
-- results somebody actually wanted.
--
-- Generated and stored for the same reason mode is: computed once at write time,
-- never out of step with the text it came from, and indexable.
alter table public.item
  add column search tsvector generated always as (
    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(description, '')), 'B')
  ) stored;

create index item_search_idx on public.item using gin (search) where deleted_at is null;
