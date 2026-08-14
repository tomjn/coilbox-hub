-- The three writes the moderation grid makes, moved into the database (issue
-- #115).
--
-- They were three PostgREST updates made with the secret key, which worked and
-- recorded nothing about who made them. service_role has no session behind it,
-- so auth.uid() is null on every one of those writes and the audit trail added
-- in 20260814220100 would have said "somebody" for every decision. Putting an
-- actor in the payload instead would have meant trusting a value the caller
-- typed, which is not what an audit trail is for.
--
-- So the moderator's own session calls these, the functions are security
-- definer, and the actor is auth.uid() read from that session's token. The
-- capability check moves in here with them, which is the other half of the
-- swap: `authenticated` holds no update grant on public.asset and still does
-- not, so these functions are the only way a session changes a moderation
-- state, and each one refuses anybody without can_moderate before it looks at
-- its arguments.
--
-- The server actions in app/moderation/assets/actions.ts still ask
-- is_moderator() first. That is not this check repeated, it is the difference
-- between a page that quietly does nothing for a stranger and one that returns
-- an error telling them what they are missing.

-- Approve everything ticked, and say how many rows actually moved.
--
-- Narrowed to rows that are still pending, which is the behaviour the grid
-- already had: a moderator holding an hour old tab must not re-approve
-- something another moderator has since rejected.
create function public.approve_assets(ids uuid[]) returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
  moved integer;
begin
  if not public.is_moderator() then
    raise exception 'approving pictures needs can_moderate'
      using errcode = 'insufficient_privilege';
  end if;

  with approved as (
    update public.asset
    set moderation = 'approved', approval_source = 'moderator'
    where id = any(approve_assets.ids) and moderation = 'pending'
    returning id
  )
  select count(*)::integer into moved from approved;

  return moved;
end;
$$;

-- Reject one picture, saying which kind of rejection it is.
--
-- `unrecorded` is refused. It is what 20260814220000 backfilled onto rows
-- rejected before there were kinds, and a moderator deciding today has no
-- excuse for not saying which decision they made.
--
-- Already rejected rows are left alone rather than re-labelled. A safety
-- rejection is final and the trigger on the table would refuse anyway, and an
-- editorial one has to come back through the queue to be reconsidered, which
-- is what public.return_asset is for. Escalating an editorial rejection to a
-- safety one without that round trip is the one case this shuts out, and there
-- is no screen to do it from today.
--
-- Approved rows are in scope, deliberately. Rejecting something the hub is
-- already serving is the case asset_approval_state_check was written for, and
-- approval_source is left alone so the row goes on saying how it came to be
-- approved before somebody rejected it.
create function public.reject_asset(asset_id uuid, kind text) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  rejected uuid;
begin
  if not public.is_moderator() then
    raise exception 'rejecting a picture needs can_moderate'
      using errcode = 'insufficient_privilege';
  end if;

  if kind not in ('safety', 'editorial') then
    raise exception 'a rejection is either safety or editorial, not %', kind
      using errcode = 'check_violation';
  end if;

  update public.asset
  set moderation = 'rejected', rejection_kind = reject_asset.kind
  where id = reject_asset.asset_id and moderation <> 'rejected'
  returning id into rejected;

  return rejected is not null;
end;
$$;

-- Put an editorial rejection back in the queue.
--
-- This is the whole practical difference the two kinds make, and the reason
-- the grid asks which one it is rather than recording a note for the file. An
-- editorial rejection is somebody's call about whether a picture belongs, and
-- calls get made wrongly. A safety rejection is not that, so this refuses one,
-- and asset_safety_rejection_is_final refuses it again underneath if anything
-- ever gets past this.
--
-- approval_source has to be nulled, because asset_approval_state_check will
-- not have a pending row that says what approved it, and it should not: this
-- row is going back to a moderator who has to decide about it afresh. What is
-- lost by that is the record of a prior approval, and public.asset_event has
-- held it since 20260814220100, which is what makes nulling it safe now and
-- would not have been before.
create function public.return_asset(asset_id uuid) returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  returned uuid;
begin
  if not public.is_moderator() then
    raise exception 'returning a picture to the queue needs can_moderate'
      using errcode = 'insufficient_privilege';
  end if;

  update public.asset
  set moderation = 'pending', rejection_kind = null, approval_source = null
  where id = return_asset.asset_id
    and moderation = 'rejected'
    and rejection_kind <> 'safety'
  returning id into returned;

  return returned is not null;
end;
$$;

-- Execute is granted to PUBLIC on every new function, so these revokes are the
-- access control and not a tidy-up. A signed out visitor can never satisfy
-- is_moderator(), and there is still no reason for the call to be reachable
-- without a session.
revoke execute on function public.approve_assets(uuid[]) from public;
revoke execute on function public.reject_asset(uuid, text) from public;
revoke execute on function public.return_asset(uuid) from public;

grant execute on function public.approve_assets(uuid[]) to authenticated;
grant execute on function public.reject_asset(uuid, text) to authenticated;
grant execute on function public.return_asset(uuid) to authenticated;
