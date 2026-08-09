-- A Discord account is still an account somebody can abuse, and the free tier
-- fails by cutting off rather than billing, so a burst of junk takes the whole
-- gallery down rather than costing money.
--
-- The limit lives here rather than in the publish action because PostgREST is a
-- write path too. A check in the application would be one door locked and another
-- standing open.
--
-- Twenty an hour is far past anything a person doing this by hand will meet. It
-- is a ceiling on automation, not a pace for people.
create function public.enforce_publish_rate_limit() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  recent integer;
begin
  select count(*) into recent
  from public.item
  where author_id = new.author_id
    and created_at > now() - interval '1 hour';

  if recent >= 20 then
    raise exception 'Too many published in the last hour. Try again later.'
      using errcode = '53400';
  end if;

  return new;
end;
$$;

create trigger item_publish_rate_limit
  before insert on public.item
  for each row execute function public.enforce_publish_rate_limit();
