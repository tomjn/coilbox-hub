-- The companion to table_privileges.test.sql, and the check that would have
-- caught 20260827120000 four months earlier. Issue #59 was about default
-- privileges handing anon and authenticated a grant on every new table, and the
-- same default covers functions and sequences. Nothing asserted that half, so
-- when Supabase CLI v2.116.0 changed auto_expose_new_tables to default to true
-- the suite found out sideways, through five behavioural assertions in four
-- unrelated files.
--
-- Behavioural tests cannot cover this on their own. They only ever run against
-- a database built from these migrations, and a function that is reachable
-- because of a stray grant behaves exactly like one that is reachable because
-- a migration said so. These assert the default itself is shut, so the function
-- written next week is closed without anybody remembering to check.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

-- A function starts life with execute for PUBLIC, which is why every migration
-- here that creates one revokes it. That revoke is the part the suite already
-- exercises. What it never checked is what happens after it: whether the three
-- Data API roles are left holding a grant nobody wrote. Create a function the
-- way a migration does, inside this rolled-back transaction, and ask.
create function public._function_privileges_probe() returns int
  language sql as 'select 1';
revoke execute on function public._function_privileges_probe() from public;

select function_privs_are('public', '_function_privileges_probe', ARRAY[]::text[],
  'anon', ARRAY[]::name[],
  'a newly created function grants anon nothing once PUBLIC is revoked');
select function_privs_are('public', '_function_privileges_probe', ARRAY[]::text[],
  'authenticated', ARRAY[]::name[],
  'a newly created function grants authenticated nothing once PUBLIC is revoked');
select function_privs_are('public', '_function_privileges_probe', ARRAY[]::text[],
  'service_role', ARRAY[]::name[],
  'a newly created function grants service_role nothing, so a route reaching one is a grant somebody wrote');

-- Sequences never had a grant to any of the three, and none of them should
-- start with one. Usage is the ability to burn ids or move a sequence, which is
-- not something a browser has a use for.
create sequence public._sequence_privileges_probe;

select sequence_privs_are('public', '_sequence_privileges_probe', 'anon',
  ARRAY[]::name[],
  'a newly created sequence grants anon nothing');
select sequence_privs_are('public', '_sequence_privileges_probe', 'authenticated',
  ARRAY[]::name[],
  'a newly created sequence grants authenticated nothing');
select sequence_privs_are('public', '_sequence_privileges_probe', 'service_role',
  ARRAY[]::name[],
  'a newly created sequence grants service_role nothing');

select * from finish();
rollback;
