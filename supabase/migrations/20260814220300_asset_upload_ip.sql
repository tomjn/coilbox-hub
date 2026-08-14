-- Where an upload came from, kept for as long as it can matter and not longer
-- (issue #115).
--
-- The issue asks for uploader identity, timestamp and source IP per asset,
-- because a report needs all three. Two of them were already on the row:
-- uploaded_by names the account and created_at says when. The address was not,
-- and adding it is not the same kind of change as adding the other two. An
-- account is something somebody chose to create here. An IP address is
-- personal data about everybody who ever uploads anything, whether or not they
-- did something worth reporting, and a column that quietly accumulates one per
-- picture forever is the wrong default even when nobody ever reads it.
--
-- So it is a table of its own rather than a column, and that buys three
-- things.
--
-- Nothing can read it. public.asset carries a table wide select grant to anon
-- and authenticated, narrowed to approved rows by asset_read_approved, so a
-- column here would be served to the public on every approved picture. This
-- table grants select to nobody at all, service_role included. The hub can
-- write an address and cannot read one back, and the only way to see one is to
-- be the maintainer at the database.
--
-- Retention is enforceable. The rule below is a trigger rather than a promise,
-- and there is no scheduled job on this project to hang a sweep off.
--
-- Deleting it is a delete of one row. Dropping a column from a live table with
-- a decision attached to it is not.
--
-- ## The rule
--
-- Kept while the picture is pending or rejected. Purged the moment it is
-- approved.
--
-- The address exists to support a report about an upload the hub refused. Once
-- the hub has looked at a picture and decided to publish it, the upload was
-- ordinary traffic and there is nothing left to report, so keeping the address
-- serves nobody. A safety rejection keeps it indefinitely, which is the case
-- the issue is actually about.
--
-- What that costs: a picture approved by mistake and found to be a problem
-- afterwards has no address behind it any more. uploaded_by and created_at are
-- still there, and the Discord account is the better anchor of the two anyway.
-- Moving the line is one predicate in the trigger at the foot of this file, and
-- where the line goes is the maintainer's call rather than this migration's.
create table public.asset_upload_ip (
  id bigint generated always as identity primary key,

  asset_id uuid not null references public.asset (id),

  -- inet rather than text, so a malformed value is refused here rather than
  -- discovered while somebody is trying to compile a report. The route parses
  -- it before it gets this far and writes nothing when it cannot.
  ip inet not null,

  at timestamptz not null default now()
);

-- Appended rather than kept one per asset, because a replacement (#106) is a
-- second upload of the same row and may come from somewhere else. The row
-- keeps only the newest bytes, and this keeps both arrivals.
create index asset_upload_ip_asset_idx on public.asset_upload_ip (asset_id);

revoke all on public.asset_upload_ip from anon, authenticated, service_role;

-- Write only, which is the whole of it. The upload route records an address
-- and nothing in the application ever asks for one back, so a select grant
-- would be a way to read every uploader's address out of a stolen secret key
-- in exchange for nothing.
grant insert on public.asset_upload_ip to service_role;

alter table public.asset_upload_ip enable row level security;

-- Security definer, so the purge does not need a delete grant to exist for
-- anybody. Nothing but this can remove a row.
create function public.purge_asset_upload_ip() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  delete from public.asset_upload_ip where asset_id = new.id;
  return null;
end;
$$;

create trigger asset_purge_upload_ip
  after update on public.asset
  for each row
  when (new.moderation = 'approved' and old.moderation is distinct from 'approved')
  execute function public.purge_asset_upload_ip();
