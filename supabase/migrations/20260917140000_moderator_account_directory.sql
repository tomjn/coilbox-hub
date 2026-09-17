-- The account directory a moderator needs to find a Hub account (issue #385).
--
-- "Authors" in the moderation bar is about names credited inside map archives,
-- which is not a Hub account. What did not exist anywhere was a way to answer
-- "who is this account?" from the hub itself: the only reads the schema hands
-- out are has_capability() and is_moderator(), and both answer for the caller
-- alone. Deliberately (20260814160000), so a holder cannot enumerate the
-- capability table one question at a time. A directory is the legitimate
-- version of that question, and this is the one place it gets asked.
--
-- ## Why a function and not a view
--
-- auth.users is outside the public schema and holds email, phone, encrypted
-- password and provider metadata for every account. A view over it either runs
-- as its owner (then the grant to read the view is the whole of the access
-- control, with nothing else in the way) or as the invoker (then the invoker
-- needs direct select on auth.users, which is far wider than this page needs).
-- A security definer function keeps auth.users behind the function body, so the
-- columns listed here are the only ones anybody can reach.
--
-- ## The check is in the body
--
-- security definer means the function reads as the table owner, so the grant to
-- execute it is the whole of who may read the directory. is_moderator() is
-- therefore asked inside the body, before the first row is touched, the same
-- way clear_map_facts conditions its delete. A page-level check alone would
-- leave a granted function any signed-in session could call.
--
-- ## What is returned, and what is not
--
-- id, the display name Discord gave (read in the order lib/author.ts reads it,
-- so a directory row and the site header agree), created_at, and the
-- capabilities the account holds. Nothing else: no email, no phone, no provider
-- tokens, no last sign in. Investigating a report needs identity and standing,
-- and the rest is the auth table's business.
--
-- capabilities is one aggregate over user_capability joined onto the directory
-- scan, not a subquery per account.
--
-- ## Search and paging
--
-- The search matches the account id exactly, or the display name containing the
-- term case-insensitively. The ILIKE argument is not a hand-escaped pattern:
-- Postgres treats the whole term literally here, so a name with a % in it
-- searches for a %, which is the same trust-the-database-with-the-string
-- reasoning that keeps typed author keys away from hand-built PostgREST filters
-- in lib/maps/authorMerge.ts.
--
-- Page size is fixed at 50 and the offset is the page handle. Sorting by
-- created_at desc puts a fresh troublemaker near the top; the id breaks ties so
-- two accounts created in the same instant page in a stable order.
create function public.moderator_accounts(p_search text, p_offset integer)
returns table (
  id uuid,
  display_name text,
  created_at timestamptz,
  capabilities text[]
)
language plpgsql
stable
security definer
set search_path = ''
as $$
begin
  if not public.is_moderator() then
    raise exception 'moderator_accounts is for moderators' using errcode = '42501';
  end if;

  return query
  with directory as (
    select
      u.id,
      coalesce(
        u.raw_user_meta_data ->> 'full_name',
        u.raw_user_meta_data ->> 'name',
        u.raw_user_meta_data ->> 'preferred_username',
        u.raw_user_meta_data ->> 'user_name',
        ''
      ) as display_name,
      u.created_at
    from auth.users u
    where
      p_search is null
      or u.id::text = p_search
      or coalesce(
        u.raw_user_meta_data ->> 'full_name',
        u.raw_user_meta_data ->> 'name',
        u.raw_user_meta_data ->> 'preferred_username',
        u.raw_user_meta_data ->> 'user_name',
        ''
      ) ilike '%' || p_search || '%'
  )
  select
    d.id,
    d.display_name,
    d.created_at,
    coalesce(
      array_agg(uc.capability order by uc.capability) filter (where uc.user_id is not null),
      '{}'
    )
  from directory d
  left join public.user_capability uc on uc.user_id = d.id
  group by d.id, d.display_name, d.created_at
  order by d.created_at desc, d.id
  offset greatest(coalesce(p_offset, 0), 0)
  limit 50;
end;
$$;

-- Access. Whatever the roles hold is taken away first, the same discipline
-- every function here follows since #59.
revoke execute on function public.moderator_accounts(text, integer) from public;

-- The moderation page calls this through the visitor's own session, so
-- authenticated is the grant and the body check is the control. anon gets
-- nothing, which the function_privileges test asserts.
grant execute on function public.moderator_accounts(text, integer) to authenticated;
