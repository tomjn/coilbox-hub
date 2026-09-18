-- Who may put a map at the top of the catalog listing, run as the roles
-- PostgREST actually uses (#394).
--
-- The mechanism is the game one (game_featured.test.sql): service_role writes,
-- after the server action has asked is_moderator() with the caller's own
-- session. What is worth proving here is that public.map still refuses the
-- write to anybody else. Unlike public.game, nothing on this table is an
-- owner's to write at all, so there is no second column set to check a
-- stranger still has - only that they have none of this one.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.map (
  id, map_name, slug,
  width_elmos, height_elmos, world_height_min, world_height_max,
  source_hash, source_archive, catalog_version, facts_digest
)
values (
  '0f8fad5b-0007-4000-8000-000000000042', 'Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8',
  6144, 10240, -120.5, 890,
  'src-comet', 'comet_catcher_remake_1.8.sd7', 1, 'digest-comet'
);

-- service_role features it, which is what setMapFeatured does once it has
-- checked who is asking.
reset role;
set local role service_role;

update public.map
set featured_at = now(), featured_by = 'cccccccc-cccc-cccc-cccc-cccccccccccc'
where slug = 'comet-catcher-remake-1-8';

select is(
  (select featured_at is not null from public.map where slug = 'comet-catcher-remake-1-8'),
  true,
  'service_role can feature a map'
);

-- anon reads it, because the catalog listing has to draw the featured badge
-- for a signed out visitor.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select featured_at is not null from public.map_browse where slug = 'comet-catcher-remake-1-8'),
  true,
  'anon reads featured_at through the browse view'
);

select is(
  (select count(*)::integer from public.map_browse where slug = 'comet-catcher-remake-1-8'),
  1,
  'featuring does not change what anon can see'
);

-- A signed in stranger may not write it. public.map grants authenticated
-- select alone (20260818100000_map_catalog.sql), so this is refused before any
-- policy is consulted - there is no owner's pen on this table for the column
-- grant to have to stay outside of.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select throws_ok(
  $$update public.map set featured_at = null where slug = 'comet-catcher-remake-1-8'$$,
  '42501',
  null,
  'a signed in stranger may not feature or unfeature a map'
);

-- service_role can take it back down again, and unfeaturing clears both
-- columns rather than leaving a stale name on an ordinary row.
reset role;
set local role service_role;

update public.map
set featured_at = null, featured_by = null
where slug = 'comet-catcher-remake-1-8';

select is(
  (select featured_at from public.map where slug = 'comet-catcher-remake-1-8'),
  null::timestamptz,
  'service_role can unfeature a map, clearing featured_at'
);

select is(
  (select featured_by from public.map where slug = 'comet-catcher-remake-1-8'),
  null::uuid,
  'and featured_by along with it'
);

select * from finish();
rollback;
