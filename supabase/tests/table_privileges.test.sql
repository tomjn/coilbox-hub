-- A grant and a policy are two different layers, and issue #59 found a
-- production project holding grants these migrations never wrote: default
-- privileges on the hosted project were handing anon and authenticated
-- select on new tables in public, so report and moderator were readable
-- the moment RLS let anything through, with no grant standing in the way
-- first. pgTAP's behavioural tests never catch that, because they only run
-- against a database built from these migrations, where the grant was
-- always correctly absent. These assert the grants directly.

begin;
select plan(63);

create extension if not exists pgtap with schema extensions;

-- public.item: the public gallery. anon and authenticated both read it;
-- authenticated may delete outright, and insert and update are per column
-- rather than table wide (20260809151352, 20260809190500), so those are
-- checked on specific columns rather than at the table level. service_role
-- holds nothing (issue #43).
select table_privs_are('public', 'item', 'anon', ARRAY['SELECT'],
  'anon can only select on item');
select table_privs_are('public', 'item', 'authenticated',
  ARRAY['SELECT', 'DELETE'],
  'authenticated can select and delete on item at the table level');
select table_privs_are('public', 'item', 'service_role', ARRAY[]::name[],
  'service_role holds no table privilege on item');

-- title: an author may publish and later edit it. Every column is also
-- selectable, from the table-wide select grant above.
select column_privs_are('public', 'item', 'title', 'authenticated',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'authenticated can insert and update item.title');
-- container: set once at publish time, never edited afterwards, so a
-- changed payload cannot appear under a URL already shared.
select column_privs_are('public', 'item', 'container', 'authenticated',
  ARRAY['SELECT', 'INSERT'],
  'authenticated can insert but not update item.container');
-- author_name: derived from the account at insert time (current_author_name
-- default), never supplied by the client at all.
select column_privs_are('public', 'item', 'author_name', 'authenticated',
  ARRAY['SELECT'],
  'authenticated can neither insert nor update item.author_name');

-- public.asset: approved rows are public and nothing else is, so anon and
-- authenticated read and write nothing at all. The route writes as
-- service_role, which reads before it writes and never deletes (issue #102).
-- asset_read_approved is the second layer, and asset_access.test.sql is what
-- proves it narrows this select grant to approved rows. #114 kept it that way:
-- the moderation grid reads the queue as service_role rather than through a
-- policy of its own, so a moderator's session holds exactly what is listed
-- here and no more.
select table_privs_are('public', 'asset', 'anon', ARRAY['SELECT'],
  'anon can only select on asset');
select table_privs_are('public', 'asset', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on asset, the same as anon');
select table_privs_are('public', 'asset', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'service_role can read and write asset, and cannot delete');

-- public.asset_event: the audit trail (issue #115), which nothing writes but
-- the trigger that keeps it. The trigger is security definer and therefore
-- writes as the table owner, so no role holds insert, update or delete, and a
-- compromised secret key can make decisions it cannot then tidy up after. The
-- trail page reads it server side, which is the one grant.
select table_privs_are('public', 'asset_event', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_event');
select table_privs_are('public', 'asset_event', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_event, so who moderated what is not served to a browser');
select table_privs_are('public', 'asset_event', 'service_role', ARRAY['SELECT'],
  'service_role reads the audit trail and cannot write or erase a line of it');

-- public.asset_upload_ip: where an upload came from (issue #115). Write only,
-- including for the secret key, so the hub can record an address and nothing
-- in it can read one back. Retention is a trigger on public.asset rather than
-- a grant, and asset_rejection.test.sql is what proves it.
select table_privs_are('public', 'asset_upload_ip', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_upload_ip');
select table_privs_are('public', 'asset_upload_ip', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_upload_ip');
select table_privs_are('public', 'asset_upload_ip', 'service_role', ARRAY['INSERT'],
  'service_role records an uploader address and cannot read one back');

-- public.asset_source_conflict: two clients disagreeing about what one archive
-- contains (issue #116). Read and written server side and nowhere else: the
-- upload route records a disagreement and the contact sheet reads which
-- pictures have one. A reported hash names bytes nobody has reviewed and the
-- reporter is not the queue's to publish, so a browser holds neither.
select table_privs_are('public', 'asset_source_conflict', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_source_conflict');
select table_privs_are('public', 'asset_source_conflict', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_source_conflict');
select table_privs_are('public', 'asset_source_conflict', 'service_role', ARRAY['SELECT', 'INSERT'],
  'service_role records a disagreement and reads it back, and can neither change nor erase one');

-- public.asset_withdrawal: what has to come out of the durable tier after a
-- safety rejection arrived too late to stop it going in (issue #153). Select
-- only, and only server side: the promotion job reads the queue, the two
-- functions in 20260814240000 are the only writers, and the list names a file
-- the hub is trying to take down, which is not a list to hand a browser.
select table_privs_are('public', 'asset_withdrawal', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_withdrawal');
select table_privs_are('public', 'asset_withdrawal', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_withdrawal');
select table_privs_are('public', 'asset_withdrawal', 'service_role', ARRAY['SELECT'],
  'service_role reads the takedown queue and can neither add to it nor settle a row by hand');

-- public.asset_orphan: staging objects nothing points at, waiting to be swept
-- (issue #113). Select only, and only server side. A row names a reachable
-- object in a public store holding bytes nobody has reviewed, so handing a
-- browser this list would hand it the pending pictures the moderation queue
-- exists to keep out of sight. The trigger and the two functions in
-- 20260814250000 are the only writers.
select table_privs_are('public', 'asset_orphan', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_orphan');
select table_privs_are('public', 'asset_orphan', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_orphan');
select table_privs_are('public', 'asset_orphan', 'service_role', ARRAY['SELECT'],
  'service_role reads the sweep queue and can neither add to it nor settle a row by hand');

-- public.asset_licence: recorded research a moderator can read server side, and
-- written only by a migration, where a person reviews the finding before it
-- becomes permanent. No upload consults it any more (#167). Nothing serves it
-- to a browser, so anon and authenticated hold nothing (issue #102).
select table_privs_are('public', 'asset_licence', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_licence');
select table_privs_are('public', 'asset_licence', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_licence');
select table_privs_are('public', 'asset_licence', 'service_role', ARRAY['SELECT'],
  'service_role can read asset_licence and cannot change a decision');

-- The map catalog (issue #182). Five tables the whole world may read and only
-- the routes may write, and one the routes keep to themselves.
--
-- The read side is table wide and every row, unlike public.asset, because a
-- catalog row describes a map that is already published everywhere else and
-- there is no queue in front of it. The write side is where the layers matter:
-- the routes compute slug, facts_digest and the author keys, so a client
-- holding the publishable key writing a row directly would produce one that is
-- unreachable by URL, reads as unchanged facts forever and is credited to
-- nobody. map_access.test.sql is what proves the behaviour of both.
select table_privs_are('public', 'map', 'anon', ARRAY['SELECT'],
  'anon can only select on map');
select table_privs_are('public', 'map', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map, the same as anon');
select table_privs_are('public', 'map', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'service_role can read and write map, and cannot delete, since a superseded map is still a map that existed');

-- map_point and map_author are the two that hold a set rather than a row, so
-- the routes hold delete on them as well: a resubmission replaces a map's
-- points and credits, and one that loses a metal spot or a co-author has to
-- lose the row too.
select table_privs_are('public', 'map_point', 'anon', ARRAY['SELECT'],
  'anon can only select on map_point');
select table_privs_are('public', 'map_point', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_point');
select table_privs_are('public', 'map_point', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
  'service_role rewrites the points on a map, which means taking the old set away');

select table_privs_are('public', 'map_author', 'anon', ARRAY['SELECT'],
  'anon can only select on map_author');
select table_privs_are('public', 'map_author', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_author');
select table_privs_are('public', 'map_author', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
  'service_role rewrites the credits on a map for the same reason');

select table_privs_are('public', 'author_alias', 'anon', ARRAY['SELECT'],
  'anon can only select on author_alias, which a listing needs to group maps by author at all');
select table_privs_are('public', 'author_alias', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on author_alias');
select table_privs_are('public', 'author_alias', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'service_role records that two keys are one person, and repoints an alias rather than removing it');

select table_privs_are('public', 'map_mirror_host', 'anon', ARRAY['SELECT'],
  'anon can only select on map_mirror_host, since a download link is the point of the table');
select table_privs_are('public', 'map_mirror_host', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_mirror_host');
select table_privs_are('public', 'map_mirror_host', 'service_role',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'service_role turns a mirror off rather than deleting it, so the template that worked is still there');

-- public.map_listing: the kind of map each row is, worked out from what was
-- measured (issue #184). Select for all three, and nothing else for anybody. It
-- publishes no column public.map does not already hand every reader, and it is
-- security invoker, so the row level security on the table behind it applies to
-- whoever queries the view rather than to its owner. The routes read it too,
-- because #188 answers a lookup from it server side. map_listing.test.sql is
-- what proves each role really gets rows out of it.
select table_privs_are('public', 'map_listing', 'anon', ARRAY['SELECT'],
  'anon can only select on map_listing');
select table_privs_are('public', 'map_listing', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_listing');
select table_privs_are('public', 'map_listing', 'service_role', ARRAY['SELECT'],
  'service_role can only select on map_listing, since the tags are computed and nothing writes them');

-- public.map_browse: what the listing page filters and sorts on (issue #189).
-- A second view on top of map_listing rather than six more columns on it, so
-- the read a lookup makes does not pay for a page it never renders. The same
-- three select grants: anon and authenticated draw the page, and service_role
-- holds it for the reason map_listing grants it, though nothing reads it that
-- way today. map_browse.test.sql is what proves each column.
select table_privs_are('public', 'map_browse', 'anon', ARRAY['SELECT'],
  'anon can only select on map_browse');
select table_privs_are('public', 'map_browse', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on map_browse');
select table_privs_are('public', 'map_browse', 'service_role', ARRAY['SELECT'],
  'service_role can only select on map_browse, since every column on it is computed');

-- public.author_display_name: the one spelling a mapper is shown under (issue
-- #189). The same three select grants and for the same reasons. It publishes
-- nothing public.map_author does not, it is security invoker so the read all
-- policy on that table applies to whoever queries it, and service_role reads it
-- because public.map_facts joins it. map_browse.test.sql is what proves the
-- name it answers with.
select table_privs_are('public', 'author_display_name', 'anon', ARRAY['SELECT'],
  'anon can only select on author_display_name');
select table_privs_are('public', 'author_display_name', 'authenticated', ARRAY['SELECT'],
  'authenticated can only select on author_display_name');
select table_privs_are('public', 'author_display_name', 'service_role', ARRAY['SELECT'],
  'service_role can only select on author_display_name, since the name is computed and nothing writes it');

-- public.map_source_conflict: two clients disagreeing about what one archive
-- contains, and the same line asset_source_conflict draws. The reported hash
-- names facts nobody has checked and who reported them is not the public's
-- business, so a browser holds neither.
select table_privs_are('public', 'map_source_conflict', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on map_source_conflict');
select table_privs_are('public', 'map_source_conflict', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on map_source_conflict');
select table_privs_are('public', 'map_source_conflict', 'service_role', ARRAY['SELECT', 'INSERT'],
  'service_role records a disagreement and reads it back, and can neither change nor erase one');

-- public.user_capability: who holds a capability is not public, and neither
-- is the fact that anybody does. Nobody gets a table grant, not even
-- authenticated or service_role. has_capability() reads it as its own definer,
-- and is_moderator() is one question asked of it (issue #101). This replaces
-- public.moderator, which that migration folded into this table. #102 settled
-- the two questions #101 left: a holder does not read their own capabilities
-- back, and only the table owner grants or revokes.
select table_privs_are('public', 'user_capability', 'anon', ARRAY[]::name[],
  'anon has no table privilege on user_capability');
select table_privs_are('public', 'user_capability', 'authenticated', ARRAY[]::name[],
  'authenticated has no table privilege on user_capability');
select table_privs_are('public', 'user_capability', 'service_role', ARRAY[]::name[],
  'service_role has no table privilege on user_capability');

-- public.report: anyone may insert; only authenticated may select or
-- update, and report_read_moderators / report_handle_moderators are what
-- actually narrow that down to a moderator.
select table_privs_are('public', 'report', 'anon', ARRAY['INSERT'],
  'anon can only insert on report');
select table_privs_are('public', 'report', 'authenticated',
  ARRAY['SELECT', 'INSERT', 'UPDATE'],
  'authenticated can select, insert and update on report');
select table_privs_are('public', 'report', 'service_role', ARRAY[]::name[],
  'service_role has no table privilege on report');

-- The drift issue #59 describes is not about these three tables in
-- particular, it is about whatever grants a table the moment it is
-- created. Prove the default itself is shut, not just its current effect,
-- by creating a table inside this rolled-back transaction and checking
-- what it starts with.
create table public._table_privileges_probe (id int);

select table_privs_are('public', '_table_privileges_probe', 'anon', ARRAY[]::name[],
  'a newly created table grants anon nothing by default');
select table_privs_are('public', '_table_privileges_probe', 'authenticated', ARRAY[]::name[],
  'a newly created table grants authenticated nothing by default');
select table_privs_are('public', '_table_privileges_probe', 'service_role', ARRAY[]::name[],
  'a newly created table grants service_role nothing by default');

select * from finish();
rollback;
