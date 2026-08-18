-- Two spellings of one mapper are one author (issue #183).
--
-- A map's author is free text out of mapinfo.lua. Nothing validates it and
-- nobody agreed a convention, so one person arrives as `Beherith`, `beherith`
-- and `[BAR]Beherith`, and two people arrive as `Beherith & Icexuick` in a
-- single string. Asking for every map by beherith against the raw value returns
-- a fraction of them and splits the rest across pages nobody finds.
--
-- These three functions are the whole distance between the credit an archive
-- declares and the key public.map_author.key files it under. One splits a
-- credit string into the people it names, one turns a person into a key, and
-- one applies a maintainer's merge on top.
--
-- In the database rather than in the ingest route, even though the route is
-- what writes the keys. Every read path has to arrive at the same key from a
-- name somebody typed or clicked, so the rules are needed on both sides, and
-- two copies of a normaliser in two languages drift. The failure that drift
-- causes is silent: an author page listing nothing while the credits sit in the
-- table, correctly keyed, under a key the reader spelled a hair differently.
--
-- ## Splitting a credit is a guess, and that is allowed here
--
-- The schema is firm that map_name is never parsed, and 20260814090000 writes
-- out why: mapper conventions are inconsistent enough that a parse failure
-- either invents a duplicate map or collides two genuinely different ones.
-- Splitting a credit on `&` is the same class of guess.
--
-- What differs is what a wrong answer costs. A bad map_name parse corrupts
-- identity, permanently and invisibly. A bad credit split invents an author
-- page nobody visits, and public.author_alias then merges it away. That is the
-- allowance public.asset already makes for deriving a family name for display,
-- where being wrong is a cosmetic grouping error and nothing worse.

-- Everybody one credit string names.
--
-- Splits on the four separators mapinfo credits actually use, trims each part
-- and drops the empties. `Beherith & Icexuick` is two credits and `Beherith` is
-- one. A single author still comes back as a one element array, so every caller
-- takes the same path and nothing has to ask which shape it got.
--
-- The parts keep the archive's own spelling and its own order. The spelling is
-- what public.map_author.raw shows a reader, and the order is
-- public.map_author.credit_index, which is not the hub's to reorder, so the
-- split is ordered explicitly rather than left to however the aggregate
-- happened to see the rows.
--
-- `and` is matched as a whole word, which is what \m and \M are for. Without
-- them `Sandra` is two credits and one of them is `Sr`, and neither is a
-- person. The match is case insensitive because a credit reads `and`, `AND` or
-- `And`, and only the separator is folded here. Folding a name is
-- public.author_key's job below.
--
-- Null and an empty string both give an empty array. A credit string with
-- nothing in it names nobody, and the alternative is a one element array
-- holding an empty string, which is a credit for a person whose name is
-- nothing and which every caller then has to filter out again.
create function public.author_credits(credit text) returns text[]
language sql
immutable
set search_path = ''
as $$
  select coalesce(array_agg(btrim(part) order by ordinality), '{}')
  from regexp_split_to_table(
    coalesce(author_credits.credit, ''), '[&+,]|\mand\M', 'i'
  ) with ordinality as split(part, ordinality)
  where btrim(part) <> '';
$$;

-- One credit as the hub files it.
--
-- Four steps, each undoing a way the same person is written down differently in
-- two archives.
--
-- Case goes first, because capitalisation in mapinfo is whatever the mapper
-- felt like that day.
--
-- Then one clan tag off the front and one off the back, so `[BAR]Beherith`,
-- `Beherith [BAR]` and `(BAR) Beherith` all reduce to the person. Anchored at
-- the ends, which is what leaves a handle that genuinely contains a bracket
-- alone: an inner bracket is part of the name and stripping it would key two
-- different mappers the same. The opener and closer are not required to match
-- each other, because `[BAR)` is a typo rather than a third convention, and
-- insisting on matching pairs would be three alternations saying one thing.
--
-- Then punctuation and whitespace off both ends, which is where a stray dash,
-- full stop or the spaces around a stripped tag go.
--
-- That step names punctuation and space rather than "anything that is not a
-- letter or a digit", and the difference matters. Whether Postgres calls a
-- character a letter depends on the database's ctype, so the broader rule would
-- eat the last letter of a handle written in Cyrillic or ending in an accent on
-- a database configured differently from this one. Mappers are worldwide and
-- their handles are not all ASCII.
--
-- Whitespace inside collapses last, so `Comet  Catcher` and `Comet Catcher` are
-- one key rather than two.
--
-- A credit that is nothing but a tag or punctuation keys to an empty string,
-- and ingest drops it exactly as public.author_credits drops an empty part.
-- public.map_author.key refuses a blank, so nothing can store one. That is the
-- right answer rather than a gap: `[BAR]` with no name behind it is a group,
-- not a person, and keying it as `bar` would file every clan signed map in the
-- catalog under one mapper who does not exist.
create function public.author_key(credit text) returns text
language sql
immutable
set search_path = ''
as $$
  with folded as (
    select lower(coalesce(author_key.credit, '')) as key
  ),
  untagged as (
    select regexp_replace(
      regexp_replace(key, '^\s*[\[({][^\])}]*[\])}]', ''),
      '[\[({][^\])}]*[\])}]\s*$', ''
    ) as key
    from folded
  ),
  trimmed as (
    select regexp_replace(
      regexp_replace(key, '^[[:punct:][:space:]]+', ''),
      '[[:punct:][:space:]]+$', ''
    ) as key
    from untagged
  )
  select regexp_replace(key, '\s+', ' ', 'g') from trimmed;
$$;

-- The key a credit finally counts under, once a maintainer has said which keys
-- are one person.
--
-- One hop, which is the rule public.author_alias writes down for itself. A
-- chain is a loop waiting to happen, and a maintainer merging into an alias
-- rather than into a person is the mistake to notice rather than to follow. So
-- this looks up once and returns what it finds.
--
-- That single lookup is also why nothing here can loop, whatever the table
-- holds. author_alias_not_self_check refuses an alias to itself, and a cycle
-- written across two rows resolves one hop and stops, because a lookup that
-- does not recurse cannot spin.
--
-- Returns its input unchanged when there is no alias row, so a caller keys
-- everything through this and never branches on whether a merge exists. A null
-- key has no alias and comes back null, which is the same shape of answer.
--
-- Stable rather than immutable, because it reads a table a maintainer edits. An
-- immutable function is one the planner may fold once and reuse, and the answer
-- here is meant to change the moment somebody records a merge.
--
-- Security invoker, deliberately, for the reason public.account_asset_bytes
-- gives. Everybody who calls this already reads public.author_alias: anon and
-- authenticated hold select and the read all policy, and service_role carries
-- bypassrls. A definer function would add a privilege nothing needs.
create function public.resolved_author_key(credit_key text) returns text
language sql
stable
set search_path = ''
as $$
  select coalesce(
    (
      select alias.to_key
      from public.author_alias as alias
      where alias.from_key = resolved_author_key.credit_key
    ),
    resolved_author_key.credit_key
  );
$$;

-- Execute is granted to PUBLIC on every new function, so the revokes below are
-- the access control and not a tidy-up. Nothing here reads anything a visitor
-- cannot already select, so this is not about secrecy. It is that what a role
-- may call is written down in a migration rather than inherited from a hosted
-- default, which is the drift #59 found in production.
revoke execute on function public.author_credits(text) from public;
revoke execute on function public.author_key(text) from public;
revoke execute on function public.resolved_author_key(text) from public;

-- The ingest route, which is the only thing that turns a mapinfo credit string
-- into public.map_author rows and the only thing that needs all three.
grant execute on function public.author_credits(text) to service_role;
grant execute on function public.author_key(text) to service_role;
grant execute on function public.resolved_author_key(text) to service_role;

-- And the read paths, which run on the publishable key. An author page is a
-- lookup by key, and the key comes from a name in a URL or a credit somebody
-- clicked, so a page that could not key it would have to carry its own copy of
-- these rules, which is the drift this migration exists to prevent.
--
-- public.author_credits is not granted here. Splitting is what ingest does to a
-- mapinfo string, and a read path is handed one credit at a time out of
-- public.map_author. Adding the grant when a page needs it is one line.
grant execute on function public.author_key(text) to anon, authenticated;
grant execute on function public.resolved_author_key(text) to anon, authenticated;
