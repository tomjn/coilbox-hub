-- Second factors on the accounts that hold a capability (issue #145).
--
-- The gate lives in capability_access(), which has_capability() and so
-- is_moderator() now read. Three things have to hold together:
--
--   an account with a verified factor gets nothing from an aal1 session, only
--   from an aal2 one, so a stolen first factor is not enough
--   an account with no verified factor keeps what it holds, so turning this on
--   locked nobody out, the only moderator included
--   once capabilities_require_second_factor() answers true, an account with no
--   factor is told to enrol rather than let through
--
-- The aal claim is what Supabase Auth writes into the token after a factor is
-- verified, and these tests set it the way PostgREST would.

begin;
select plan(24);

create extension if not exists pgtap with schema extensions;

-- A moderator who has enrolled, a moderator who has not, and somebody with an
-- authenticator and no capability at all.
insert into auth.users (id, instance_id, aud, role, email)
values
  ('44444444-4444-4444-4444-444444444444', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'enrolled@example.test'),
  ('55555555-5555-5555-5555-555555555555', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'unenrolled@example.test'),
  ('66666666-6666-6666-6666-666666666666', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'nobody@example.test');

insert into public.user_capability (user_id, capability)
values
  ('44444444-4444-4444-4444-444444444444', 'can_moderate'),
  ('55555555-5555-5555-5555-555555555555', 'can_moderate');

insert into auth.mfa_factors (id, user_id, friendly_name, factor_type, status, created_at, updated_at, secret)
values
  ('a4444444-0000-0000-0000-000000000001', '44444444-4444-4444-4444-444444444444', 'phone app', 'totp', 'verified', now(), now(), 'not-a-real-secret'),
  ('a6666666-0000-0000-0000-000000000001', '66666666-6666-6666-6666-666666666666', 'phone app', 'totp', 'verified', now(), now(), 'not-a-real-secret');

-- A report, so the policies that call is_moderator() can be watched as well as
-- the function itself.
insert into public.item (id, kind, kind_version, title, container, author_id, author_name)
values ('a4444444-1111-0000-0000-000000000001', 'preset', 1, 'Reported', '{"format":"coilbox","container":1,"kind":"preset","kindVersion":1,"payload":{}}', '66666666-6666-6666-6666-666666666666', 'Nobody');

insert into public.report (item_id, reason)
values ('a4444444-1111-0000-0000-000000000001', 'Something is wrong with it');

-- The rollout as shipped: nobody is required to have a factor yet.
select is(
  public.capabilities_require_second_factor(), false,
  'the migration ships with second factors required only of accounts that have one'
);

-- The enrolled moderator, signed in with Discord alone.
set local role authenticated;
set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated","aal":"aal1"}';

select is(public.is_moderator(), false,
  'a moderator with a verified factor is not a moderator on an aal1 session');
select is(public.has_capability('can_moderate'), false,
  'and has_capability says the same');
select is(public.capability_access('can_moderate'), 'verify',
  'and is told to verify a code rather than refused outright');
select is((select count(*) from public.report where item_id = 'a4444444-1111-0000-0000-000000000001')::int, 0,
  'the report policies follow, so an aal1 session reads no reports');

-- A token from before aal existed, or a claim set by hand, is not aal2.
set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated"}';

select is(public.is_moderator(), false,
  'a token with no aal claim is treated as aal1, not as aal2');

-- The same moderator after entering a code.
set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated","aal":"aal2"}';

select is(public.is_moderator(), true,
  'an aal2 session gives the enrolled moderator their capability back');
select is(public.capability_access('can_moderate'), 'granted',
  'and capability_access agrees');
select is((select count(*) from public.report where item_id = 'a4444444-1111-0000-0000-000000000001')::int, 1,
  'and the report policies let them read the queue');
select is(public.has_capability('can_seed_unit_assets'), false,
  'a second factor does not add a capability the account was never granted');
select is(public.capability_access('can_seed_unit_assets'), 'none',
  'and capability_access says it is not held at all');

-- The moderator who has not enrolled keeps access, which is the whole point of
-- rolling it out this way.
set local request.jwt.claims = '{"sub":"55555555-5555-5555-5555-555555555555","role":"authenticated","aal":"aal1"}';

select is(public.is_moderator(), true,
  'a moderator with no factor keeps moderating on an aal1 session, so nobody was locked out');
select is(public.capability_access('can_moderate'), 'granted',
  'and is not asked to verify anything');

-- An enrolment started and never finished is not a factor.
reset role;
insert into auth.mfa_factors (id, user_id, friendly_name, factor_type, status, created_at, updated_at, secret)
values ('a5555555-0000-0000-0000-000000000001', '55555555-5555-5555-5555-555555555555', 'half done', 'totp', 'unverified', now(), now(), 'not-a-real-secret');

set local role authenticated;
set local request.jwt.claims = '{"sub":"55555555-5555-5555-5555-555555555555","role":"authenticated","aal":"aal1"}';

select is(public.is_moderator(), true,
  'an unverified factor does not lock its owner out');

-- Somebody else's factor is somebody else's.
select is(public.capability_access('can_moderate'), 'granted',
  'another account having a verified factor changes nothing for this one');

-- Somebody with a factor and no capability holds nothing at any level.
set local request.jwt.claims = '{"sub":"66666666-6666-6666-6666-666666666666","role":"authenticated","aal":"aal1"}';

select is(public.capability_access('can_moderate'), 'none',
  'an account with a factor and no capability is not told to verify, it holds nothing');

set local request.jwt.claims = '{"sub":"66666666-6666-6666-6666-666666666666","role":"authenticated","aal":"aal2"}';

select is(public.is_moderator(), false,
  'and aal2 does not make it a moderator');

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(public.capability_access('can_moderate'), 'none',
  'a visitor with no session holds nothing');

-- The end state, once the owner has enrolled: every capability needs aal2.
-- Replaced inside this rolled back transaction, the way the owner's migration
-- would replace it.
reset role;
create or replace function public.capabilities_require_second_factor() returns boolean
language sql
stable
set search_path = ''
as $$ select true $$;

set local role authenticated;
set local request.jwt.claims = '{"sub":"55555555-5555-5555-5555-555555555555","role":"authenticated","aal":"aal1"}';

select is(public.is_moderator(), false,
  'once required, a moderator with no factor no longer moderates on aal1');
select is(public.capability_access('can_moderate'), 'enrol',
  'and is told to enrol, so the refusal says what to do');

set local request.jwt.claims = '{"sub":"44444444-4444-4444-4444-444444444444","role":"authenticated","aal":"aal2"}';

select is(public.is_moderator(), true,
  'an enrolled moderator on aal2 is unaffected by the switch');

reset role;

-- Who may ask. capability_access answers for the caller alone, like
-- has_capability, so the same two roles may call it. The switch is read by
-- the functions above and by nobody else.
select function_privs_are('public', 'capability_access', ARRAY['text'], 'anon',
  ARRAY['EXECUTE'], 'anon may ask what it holds, and is told nothing');
select function_privs_are('public', 'capability_access', ARRAY['text'], 'authenticated',
  ARRAY['EXECUTE'], 'authenticated may ask about itself');
select function_privs_are('public', 'capabilities_require_second_factor', ARRAY[]::text[], 'authenticated',
  ARRAY[]::name[], 'the switch is not something a session calls');

select * from finish();
rollback;
