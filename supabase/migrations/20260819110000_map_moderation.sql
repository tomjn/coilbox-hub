-- What a person has to be able to do to the catalog by hand (issue #193).
--
-- Three things in the catalog need a moderator and none of them had anywhere to
-- happen: the conflict queue nothing read, the curated tags nothing could edit,
-- and the author merges nothing could record or undo. Two of the three need
-- nothing new here. public.map already grants service_role update, which is the
-- curated tags write, and public.author_alias already grants insert and update,
-- which is recording a merge and repointing one.
--
-- So this migration is the remainder: the one read a page cannot express, the
-- one write no role may hold a grant for, and the one grant that was genuinely
-- missing.

-- How many maps each author counts for.
--
-- The author page in #193 lists keys by map count, because ordering by count
-- surfaces the useful merges first: a key with one map and a typo in it is worth
-- less attention than two keys with forty maps between them.
--
-- A view rather than a query in the page, for a plain reason. This is a grouped
-- count over public.map_author, which is one row per credit across the whole
-- catalog, and PostgREST cannot group. A page doing it for itself would have to
-- read every credit row and count them in TypeScript, which is thousands of rows
-- fetched to produce a few hundred, and PostgREST caps a reply at a thousand
-- rows anyway, so the count would quietly come out short.
--
-- The key is the resolved one rather than the stored one. public.map_author.key
-- is resolved when a submission is written, so a merge recorded afterwards is
-- not in the column, and counting the stored value would show a maintainer the
-- two keys they merged last week still sitting apart. Resolving here means the
-- list answers what the catalog counts today.
--
-- The name is public.author_display_name's, joined rather than derived, which is
-- the rule 20260818150000 sets: the spelling a mapper is shown under is worked
-- out in one place and read everywhere else. A page showing one name in the
-- merge list and another on a map card is a bug nobody would read as a bug in a
-- query.
--
-- count(distinct map_id) rather than count(*), because an archive crediting one
-- person twice is one map by them. Two credits on one map that resolve to one
-- key are the case, and it is exactly the case a merge creates.
--
-- No ordering here. The order is the reader's, and a view that carried one would
-- be an order every caller pays for and none of them is bound by.
--
-- Security invoker, for the reason public.author_display_name and
-- public.map_listing both give: everybody who reads this already reads
-- public.map_author and public.author_alias directly, so running as the owner
-- would add a privilege nothing needs and would quietly become a way round
-- map_author_read_all the day somebody narrows it.
create view public.author_map_count
with (security_invoker = true)
as
select
  shown.key,
  shown.name,
  count(distinct author.map_id)::integer as maps
from public.map_author as author
join public.author_display_name as shown
  on shown.key = public.resolved_author_key(author.key)
group by shown.key, shown.name;

-- Clear what the hub holds about one map, so the next submission stores it
-- fresh.
--
-- ## What this is for
--
-- A conflict means two installs hold different bytes under one canonical name.
-- A mapper who changes a map releases it as a new map with a version appended,
-- so one name is one archive permanently and this is never a new release. It is
-- a corrupt or modified install, and public.submit_map_facts refuses the second
-- set of facts and writes the disagreement down.
--
-- That is the right answer while the facts the hub holds are the good ones. When
-- they are not, the map is stuck: every honest client reporting the real archive
-- is refused forever, because the held source_hash is compared first and never
-- moves. Nothing else can unstick it. Taking the reported hash would write a
-- hash the hub was never given the facts behind, and the rest of the row would
-- still be the wrong reading.
--
-- So the held row goes, and the next client to report the map stores it as a map
-- the hub has never seen. What that costs is real and worth saying: the map is
-- absent from the catalog until then, its page 404s, and its curated tags go
-- with it. A moderator is told that before they press the button.
--
-- ## Why it is a function and not a delete
--
-- Two reasons, and either one on its own would be enough.
--
-- It is two deletes. public.map_source_conflict references public.map without a
-- cascade, deliberately, because a conflict row has to go on saying what the
-- disagreement was about after the map row moves on. So the records go first and
-- the map second, and a route doing that over PostgREST has no transaction
-- between them. Losing the connection in the middle would leave the evidence
-- gone and the wrong facts still there, which is the one outcome worse than
-- either half.
--
-- And nothing holds delete on public.map. 20260818100000 refuses it outright,
-- for the reason public.asset is refused it: a superseded map is a fact about a
-- map that existed. That reading is unchanged. What has changed is that there is
-- now one named reason to remove a row, so it gets one named function and no
-- role gets a grant. A definer function runs as the owner, so the ability to
-- delete a map exists only inside these fourteen lines and cannot be reached any
-- other way, however the secret key is used.
--
-- ## It refuses a map nobody has disagreed about
--
-- The count is not a formality. Without it this is a delete-any-map button
-- reachable with the secret key, and what it is meant to be is a way to settle a
-- disagreement somebody has recorded and read. A map with no conflict row is not
-- one, and comes back false rather than losing its facts.
create function public.clear_map_facts(p_map_id uuid) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  reported integer;
begin
  select count(*) into reported
  from public.map_source_conflict as conflict
  where conflict.map_id = p_map_id;

  if reported = 0 then
    return false;
  end if;

  delete from public.map_source_conflict as conflict where conflict.map_id = p_map_id;
  delete from public.map as m where m.id = p_map_id;

  return true;
end;
$$;

-- ## Access
--
-- Whatever these roles hold is taken away first, so a privilege some hosted
-- default handed out is gone rather than sitting underneath the grants below
-- where nothing would notice it. That is the discipline every table, view and
-- function has followed since #59 found production holding grants these
-- migrations never wrote.

revoke all on public.author_map_count from anon, authenticated, service_role;

-- Read for everybody, the same three grants public.author_display_name carries
-- and for the same reason: the view publishes nothing public.map_author does
-- not. Every reader already holds select on that table and passes
-- map_author_read_all, and a count is something they could arrive at themselves
-- one page of credits at a time.
--
-- Select only, and no other grant is possible to want, because every column is
-- computed and Postgres will not update this view on its own.
grant select on public.author_map_count to anon, authenticated, service_role;

-- Execute is granted to PUBLIC on every new function, so this revoke is the
-- access control rather than a tidy-up.
revoke execute on function public.clear_map_facts(uuid) from public;

-- The moderation page's server action and nothing else, which reaches Postgres
-- as service_role. A browser holding the publishable key can no more call this
-- than it can write the rows behind it.
--
-- The function is security definer, so this grant is the whole of who may remove
-- a map's facts. That is a wider thing to hand service_role than the grants
-- around it, which is why the body checks that somebody has recorded a
-- disagreement about the map first.
grant execute on function public.clear_map_facts(uuid) to service_role;

-- Removing an alias, which is the one thing #193 asks for that nothing could do.
--
-- public.author_alias already grants service_role insert and update, so
-- recording a merge and repointing one both work today. Unmerging two keys does
-- not, and repointing is not a substitute for it: there is no key that means
-- "this person is themselves", and author_alias_not_self_check refuses the row
-- that would say so.
--
-- Delete rather than a disabled column, unlike public.map_mirror_host, which is
-- turned off rather than deleted so the template that was known to work is still
-- there. An alias carries no such knowledge. It is a maintainer's judgement that
-- two keys are one person, and withdrawing it leaves nothing worth keeping: the
-- evidence for the merge is public.map_author.raw, which was never touched, and
-- which is why 20260818100000 keeps the computed key exactly as it was.
grant delete on public.author_alias to service_role;
