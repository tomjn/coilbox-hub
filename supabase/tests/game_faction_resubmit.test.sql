-- A facts resubmission keeps each faction's logo (#525).
--
-- `submit_game_facts` used to delete a game's sides and insert them again, which
-- dropped a logo seeded by hand, since only an uploaded one has an asset row for
-- `adopt_faction_logo` to restore it from. The sides a submission names are now
-- updated in place and only the ones it leaves out are deleted.

begin;
select plan(7);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('88888888-8888-8888-8888-888888888888', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'backfill@example.test');

reset role;
set local role service_role;

select public.submit_game_facts($j$
  {
    "shortname": "FRS",
    "release": "1.0.0",
    "factions": [
      {"key": "armada", "name": "Armada"},
      {"key": "cortex", "name": "Cortex"},
      {"key": "legion", "name": "Legion"}
    ],
    "units": []
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888');

reset role;

-- A logo a maintainer seeded by hand, with no asset row behind it.
update public.game_faction
set logo_path = 'factions/cortex.webp', logo_hash = 'hand'
where key = 'cortex';

-- A logo that came through an upload and was promoted.
insert into public.asset (game, faction_key, variant, source_hash, hash, encode_profile, path, origin, mime, bytes, width, height, source_archive, tier, moderation, approval_source)
values ('FRS', 'armada', 'logo', 'src-a', 'enc-a', 'webp-lossless-128', 'factions/FRS/logo/enc-a.webp', 'uploaded', 'image/webp', 3000, 64, 64, 'frs_1.sdz', 'static', 'approved', 'moderator');

set local role service_role;

create temp table resubmitted as
select public.submit_game_facts($j$
  {
    "shortname": "FRS",
    "release": "1.1.0",
    "factions": [
      {"key": "armada", "name": "Armada Renamed"},
      {"key": "cortex", "name": "Cortex Renamed"},
      {"key": "scavengers", "name": "Scavengers"}
    ],
    "units": []
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

reset role;

select is(
  (select logo_path || ' ' || logo_hash from public.game_faction where key = 'cortex'),
  'factions/cortex.webp hand',
  'a logo seeded by hand survives a resubmission'
);

select is(
  (select logo_path || ' ' || logo_hash from public.game_faction where key = 'armada'),
  'factions/FRS/logo/enc-a.webp enc-a',
  'an uploaded logo survives it too'
);

select is(
  (select string_agg(key || '=' || name, ',' order by key) from public.game_faction),
  'armada=Armada Renamed,cortex=Cortex Renamed,scavengers=Scavengers',
  'a side the submission names takes its new name and one it leaves out is deleted'
);

select is(
  (select logo_path from public.game_faction where key = 'scavengers'),
  null,
  'a side new to the game starts without a logo'
);

select is(
  (select count(*)::int from resubmitted, jsonb_array_elements(resubmitted.outcomes) as o(value) where o.value ->> 'outcome' = 'accepted'),
  3,
  'every side is answered accepted, as it was when the rows were replaced'
);

set local role service_role;

create temp table duplicated as
select public.submit_game_facts($j$
  {
    "shortname": "FRS",
    "release": "1.2.0",
    "factions": [{"key": "cortex", "name": "Cortex"}, {"key": "cortex", "name": "Cortex again"}],
    "units": []
  }
$j$::jsonb, '88888888-8888-8888-8888-888888888888') as outcomes;

reset role;

select is(
  (select array_agg(o.value ->> 'outcome' order by o.ordinality) from duplicated, jsonb_array_elements(duplicated.outcomes) with ordinality as o(value, ordinality)),
  ARRAY['accepted', 'refused'],
  'a side named twice in one submission is still refused the second time'
);

select is(
  (select name || ' ' || logo_path from public.game_faction where key = 'cortex'),
  'Cortex factions/cortex.webp',
  'and the refusal costs the side nothing'
);

select * from finish();
rollback;
