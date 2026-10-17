-- Merging many author keys into one person, and choosing their name (issue
-- #442).
--
-- The fold in 20260818110000_author_keys.sql cannot join `original from
-- Beherith`, `help from [teh]Beherith (mysterme@gmail.com)` and `Beherith`,
-- because the words around the name are part of the key. public.author_alias
-- already says that many keys are one person, since nothing stops several rows
-- pointing at the same to_key. What was missing is a way to write several of
-- them at once and a way to say what the person is called.
--
-- ## One person per credit
--
-- A credit like `original from Beherith/The_Yak` names two people, and the
-- moderator merges it into one of them. author_alias is keyed on from_key, so
-- a key resolves to exactly one person, and every reader (the listing, the
-- lookup, the counts, ingest) relies on public.resolved_author_key returning
-- one value. Letting a key resolve to two people would turn that function into
-- a set and every one of those readers into a join, for a handful of joint
-- credits. The cost of picking one is that the other person is not credited on
-- those maps.
--
-- Nothing here rewrites public.map_author. Existing rows keep the key they
-- were filed under, and the merge works through resolved_author_key as every
-- merge before it did.

-- The name a moderator chose for a person.
--
-- Keyed by the key the person counts under. Without a row, the name is the
-- most common spelling, which is the rule 20260818150000 set. With one, the
-- moderator's choice wins, because the most common spelling of a mapper can be
-- `[teh]Beherith (mysterme@gmail.com)`.
--
-- The length check is the one public.map_author.raw carries, since the name is
-- normally one of those spellings.
create table public.author_name (
  key text primary key check (length(btrim(key)) between 1 and 256),
  name text not null check (length(btrim(name)) between 1 and 256),
  set_at timestamptz not null default now()
);

alter table public.author_name enable row level security;

revoke all on public.author_name from anon, authenticated, service_role;

-- Read for everybody, because public.author_display_name is security invoker
-- and every reader of the listing reads it.
grant select on public.author_name to anon, authenticated;

create policy author_name_read_all on public.author_name
  for select to anon, authenticated
  using (true);

-- Written by public.merge_author_keys, which runs as the invoker. No delete:
-- unmerging leaves the person's chosen name where it is.
grant select, insert, update on public.author_name to service_role;

-- Every spelling in the catalog, under the key it counts as today, with the
-- number of credits it appears on.
--
-- This was the first half of public.author_display_name. It is a view of its
-- own now because the merge form offers these spellings as the name to show,
-- and the form and the name rule must count them the same way.
create view public.author_spelling
with (security_invoker = true)
as
select
  public.resolved_author_key(author.key) as key,
  author.raw,
  count(*)::integer as credits
from public.map_author as author
group by 1, 2;

revoke all on public.author_spelling from anon, authenticated, service_role;
grant select on public.author_spelling to anon, authenticated, service_role;

-- The one name to show for a person: the moderator's choice if there is one,
-- otherwise the most common spelling, with the spelling itself breaking ties.
--
-- The same two columns as before, so public.map_listing, public.map_browse,
-- public.author_map_count and public.map_facts read it unchanged.
create or replace view public.author_display_name
with (security_invoker = true)
as
select distinct on (spelling.key)
  spelling.key,
  coalesce(chosen.name, spelling.raw) as name
from public.author_spelling as spelling
left join public.author_name as chosen on chosen.key = spelling.key
order by spelling.key, spelling.credits desc, spelling.raw;

-- Merge several keys into one person, and optionally name them.
--
-- p_into and p_keys are keys exactly as public.map_author and
-- public.author_alias store them, picked off a list, and are not folded again.
-- public.author_key is not idempotent on every input (`(a) (b) name` folds to
-- `(b) name`, which folds to `name`), so folding a stored key could merge the
-- wrong one.
--
-- Answers what public.author_alias's one hop rule needs a moderator told:
--
-- - `chained` when p_into is itself merged into somebody. Merging into an
--   alias would file the maps under a key nothing counts, and quietly
--   rewriting the target would record a merge nobody asked for.
-- - `refused` when p_into is blank, or there is nothing to merge and no name.
-- - `merged` otherwise.
--
-- An alias that already points at one of the keys being merged is repointed at
-- p_into, so merging a key that has its own merges carries them along instead
-- of leaving a chain. That used to be refused, which made merging a mapper's
-- keys one at a time depend on the order they were done in.
--
-- p_keys may include p_into, and a list with only p_into and a name renames
-- the person without merging anything.
--
-- Security invoker, and only service_role may call it, which is the moderation
-- route after it has asked is_moderator().
create function public.merge_author_keys(
  p_into text,
  p_keys text[],
  p_name text,
  p_note text
) returns text
language plpgsql
set search_path = ''
as $$
declare
  v_into text := btrim(coalesce(p_into, ''));
  v_name text := nullif(btrim(coalesce(p_name, '')), '');
  v_note text := nullif(btrim(coalesce(p_note, '')), '');
  v_from text[];
begin
  if v_into = '' then
    return 'refused';
  end if;

  if exists (select 1 from public.author_alias as alias where alias.from_key = v_into) then
    return 'chained';
  end if;

  select coalesce(array_agg(distinct picked.key), '{}')
  into v_from
  from (
    select btrim(given.key) as key
    from unnest(coalesce(p_keys, '{}')) as given(key)
  ) as picked
  where picked.key <> '' and picked.key <> v_into;

  if cardinality(v_from) = 0 and v_name is null then
    return 'refused';
  end if;

  update public.author_alias as alias
  set to_key = v_into
  where alias.to_key = any(v_from);

  insert into public.author_alias (from_key, to_key, note)
  select picked.key, v_into, v_note
  from unnest(v_from) as picked(key)
  on conflict (from_key) do update
    set to_key = excluded.to_key, note = excluded.note, set_at = now();

  if v_name is not null then
    insert into public.author_name (key, name)
    values (v_into, v_name)
    on conflict (key) do update
      set name = excluded.name, set_at = now();
  end if;

  return 'merged';
end;
$$;

revoke execute on function public.merge_author_keys(text, text[], text, text) from public;
grant execute on function public.merge_author_keys(text, text[], text, text) to service_role;
