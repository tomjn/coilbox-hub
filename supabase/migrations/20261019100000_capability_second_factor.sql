-- Second factors on the accounts that hold a capability (issue #145).
--
-- A session that has verified a TOTP code carries `aal2` in its token. An
-- account that has a verified factor now gets its capabilities only from such
-- a session, so somebody holding its Discord sign in, or a copied session
-- cookie from before the code was entered, gets nothing that a capability
-- grants.
--
-- An account with no verified factor keeps what it holds on an aal1 session.
-- That is the rollout, not an oversight: requiring aal2 of everybody on the day
-- this ships would lock out every capability holder, the only moderator
-- included, because nobody has had anywhere to enrol until now. The switch for
-- the end state is capabilities_require_second_factor() below.
--
-- Everything that asks goes through has_capability(): is_moderator() calls it,
-- and so every policy and every page that calls is_moderator() follows without
-- changing. capability_access() is the same answer with the reason attached, so
-- a page can tell a moderator to enter a code rather than show them a 404.

-- The switch. False while capability holders enrol. Replacing this with a
-- function that returns true makes every capability need an aal2 session,
-- factor or not, and an account with no factor is then told to enrol. Done in
-- a migration of its own, so the decision has a date and a commit.
create function public.capabilities_require_second_factor() returns boolean
language sql
stable
set search_path = ''
as $$ select false $$;

revoke execute on function public.capabilities_require_second_factor() from public;

-- What the caller may do with one capability, and if not, why not:
--
--   granted  held, and this session is strong enough to use it
--   verify   held, the account has a verified factor, and this session has not
--            entered a code
--   enrol    held, a factor is required of everybody, and the account has none
--   none     not held, or nobody is signed in
--
-- Answers for the caller and nobody else, like has_capability(). Telling the
-- caller whether they hold something they cannot use yet tells them nothing
-- about anybody else, and it is what lets the step up page exist.
--
-- A token with no aal claim is aal1. Only the literal `aal2` that Supabase Auth
-- writes after a verify counts.
create function public.capability_access(capability text) returns text
language sql
stable
security definer
set search_path = ''
as $$
  select case
    when not exists (
      select 1 from public.user_capability uc
      where uc.user_id = auth.uid()
        and uc.capability = capability_access.capability
    ) then 'none'
    when coalesce(auth.jwt() ->> 'aal', '') = 'aal2' then 'granted'
    when exists (
      select 1 from auth.mfa_factors f
      where f.user_id = auth.uid()
        and f.status = 'verified'
    ) then 'verify'
    when public.capabilities_require_second_factor() then 'enrol'
    else 'granted'
  end;
$$;

revoke execute on function public.capability_access(text) from public;
grant execute on function public.capability_access(text) to anon, authenticated;

-- The same signature and the same grants, so nothing that calls it changes.
-- Only "held and usable" is yes now.
create or replace function public.has_capability(capability text) returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select public.capability_access(has_capability.capability) = 'granted';
$$;
