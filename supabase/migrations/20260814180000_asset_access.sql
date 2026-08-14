-- Who may read and write the three tables the asset pipeline landed today
-- (issue #102). public.asset, public.asset_licence and public.user_capability
-- all have row level security on with no policy and no grant, so they refuse
-- everyone. This settles what each role holds on each of them.
--
-- The thing that makes this worth spelling out is that the publishable key is
-- public by design. It is inlined into the browser bundle and #48 serves it as
-- JSON so a desktop client can find it, so a grant to `anon` or to
-- `authenticated` is a grant to everyone who holds that key. There is no
-- audience in between.
--
-- The Vercel Blob store is public, and an uploaded asset is at a reachable URL
-- the moment put() returns, before anybody has reviewed it. So the moderation
-- queue controls disclosure rather than reachability: what protects a pending
-- upload is that the hub never hands out its URL, and Blob's random path
-- suffix is what makes an undisclosed URL unguessable. The read policy below
-- is therefore the whole of that protection, and nothing downstream repeats
-- the check. Widening it re-exposes every pending row's path in one step.
--
-- Two layers on each table, deliberately, for the reason #59 gives: a grant
-- and a policy are independent, and either one shut is enough. Production and
-- these migrations disagreed once already, so every grant here is asserted in
-- supabase/tests/table_privileges.test.sql and every behaviour in
-- supabase/tests/asset_access.test.sql rather than written once and trusted.

-- Authoritative rather than additive, in the style of 20260810120000. Whatever
-- these three roles hold on these three tables is taken away first, so a
-- privilege some hosted default handed out is gone rather than sitting
-- underneath the grants below where nothing would notice it.
revoke all on public.asset from anon, authenticated, service_role;
revoke all on public.asset_licence from anon, authenticated, service_role;
revoke all on public.user_capability from anon, authenticated, service_role;

-- public.asset: approved rows are public, and nothing else is.
--
-- Read is table wide rather than per column. Every column on an approved row
-- describes a picture the hub is already serving, including uploaded_by, which
-- is the same disclosure item.author_id already makes about a published item.
grant select on public.asset to anon, authenticated;

create policy asset_read_approved on public.asset
  for select to anon, authenticated
  using (moderation = 'approved');

-- No insert, update or delete grant to either role, and no policy for those
-- commands. An uploader talks to the Vercel route and the route writes, so a
-- client holding the publishable key cannot set moderation, approval_source,
-- tier or path on any row, which is the whole of the queue's authority.
--
-- The absent grant is what refuses a client write, and the absent policy
-- refuses it again. Either way it errors rather than affecting nothing, which
-- matters: a silently empty write looks like success to the caller.
--
-- An uploader cannot see their own pending upload either. That is a deliberate
-- difference from public.item, where an author sees their own withdrawn item
-- because withdrawing is theirs to reverse. Approval is not the uploader's
-- decision, and a self-read policy would make moderation state readable by
-- whoever wrote the row, one upload at a time.

-- service_role, and only from the Vercel route, which holds the secret key.
--
-- Select as well as insert and update, because the route reads before it
-- writes: dedupe is a lookup on source_hash, and an insert that returns the
-- stored row needs select on it. service_role carries bypassrls, so the policy
-- above does not narrow it and the route sees pending rows.
--
-- No delete. Rejecting an asset is an update to moderation, not a delete, and
-- the seed and promotion paths (#110, #111) move rows rather than removing
-- them. Nothing has a reason to delete one yet, so nothing may.
grant select, insert, update on public.asset to service_role;

-- public.asset_licence: server side only, and read only even there.
--
-- The route has to be able to ask whether the hub may publish a game's or a
-- map's pictures before it accepts an upload, and that question is the reason
-- the table exists. Without a select grant nothing outside a migration can ask
-- it, and the licence gate cannot be built at all.
grant select on public.asset_licence to service_role;

-- Nothing for anon or authenticated. The evidence in this table is not secret,
-- it is committed in plain sight in 20260814150100, 20260814170100 and
-- 20260814170200, so the case against granting read is not confidentiality but
-- that no page serves it. An attribution page is the thing that would want it
-- and does not exist, and a grant with no reader is exactly the kind nobody
-- re-examines. It is a one line grant and a select policy when that page lands.
--
-- No insert or update for anybody, including service_role. A licence decision
-- is permanent in the way the durable tier is permanent, so it is made in a
-- migration where a human reviews it in a pull request, and not by a route at
-- runtime.

-- public.user_capability: nobody, still, and now on purpose rather than
-- pending a decision. #101 left two questions for this issue.
--
-- May a holder read their own capabilities back? No. has_capability() is
-- security definer, answers for auth.uid() alone and already answers every
-- question anything asks, including is_moderator(). A self-read policy would
-- add granted_by to what a holder can see, which names another account, and it
-- would buy nothing that the function does not already give. If a settings
-- page ever wants to list what somebody holds, that is a policy of
-- `user_id = auth.uid()` plus a select grant, and it can be argued then.
--
-- May anything other than the table owner grant or revoke? No. Handing insert
-- to any role creates a capability that hands out capabilities, and the first
-- one to be handed out would be that one. Grants stay a maintainer action
-- against the database, so there is no path from a compromised session or a
-- compromised route to a new grant. service_role gets nothing here for the
-- same reason: no route needs to write this table, and the secret key is the
-- one credential worth keeping away from privilege escalation.
--
-- So the revoke above is the whole of it, and table_privileges.test.sql
-- asserts all three roles hold nothing.
