-- A grant and a policy are two different layers, and issue #59 found a
-- production project holding grants these migrations never wrote: default
-- privileges on the hosted project were handing anon and authenticated
-- select on new tables in public, so report and moderator were readable
-- the moment RLS let anything through, with no grant standing in the way
-- first. pgTAP's behavioural tests never catch that, because they only run
-- against a database built from these migrations, where the grant was
-- always correctly absent. These assert the grants directly.

begin;
select plan(15);

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

-- public.user_capability: who holds a capability is not public, and neither
-- is the fact that anybody does. Nobody gets a table grant, not even
-- authenticated. has_capability() reads it as its own definer, and
-- is_moderator() is one question asked of it (issue #101). This replaces
-- public.moderator, which that migration folded into this table.
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
