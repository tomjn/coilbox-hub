-- The hub page link on an upload's moderation row. Run as the owner, because
-- archive_moderation_row is closed to every API role and only called by
-- functions that check who is asking.

begin;
select plan(5);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('a1111111-0000-4000-8000-0000000000b1', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'linker@example.test');
update public.profile set display_name = 'Link Uploader' where user_id = 'a1111111-0000-4000-8000-0000000000b1';

insert into public.map (
  id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  appearance, curated_tags, source_hash, source_archive, catalog_version, facts_digest
)
values ('11111111-0000-4000-8000-0000000000b1', 'Linked Map 1.0', 'linked-map-1-0', 4096, 4096, 0, 100,
  '{}'::jsonb, '{}', 'src-lm', 'linked_map_1.0.sd7', 1, 'digest-lm');

insert into public.game (id, shortname)
values ('0f8fad5b-0010-4000-8000-000000000001', 'LG');

insert into public.archive (id, sha256, filename, kind, internal_name, version, shortname, size, game_id, state, listed_at, uploader)
values
  ('22222222-0000-4000-8000-0000000000b1', repeat('b1', 32), 'linked.sd7', 'map', 'Linked Map 1.0', '', null, 10, null, 'listed', now(), 'a1111111-0000-4000-8000-0000000000b1'),
  ('22222222-0000-4000-8000-0000000000b2', repeat('b2', 32), 'lg.sdz', 'game', 'LG 1', '1', 'LG', 10, '0f8fad5b-0010-4000-8000-000000000001', 'listed', now(), null),
  ('22222222-0000-4000-8000-0000000000b3', repeat('b3', 32), 'unknown.sd7', 'map', 'Nobody Knows', '', null, 10, null, 'listed', now(), null);

select is(
  (select public.archive_moderation_row(a)->>'page' from public.archive as a where a.id = '22222222-0000-4000-8000-0000000000b1'),
  '/map/linked-map-1-0', 'a map upload links the map page with the same name');
select is(
  (select public.archive_moderation_row(a)->>'page' from public.archive as a where a.id = '22222222-0000-4000-8000-0000000000b2'),
  '/games/LG', 'a game upload links its game page');
select is(
  (select public.archive_moderation_row(a)->'page' from public.archive as a where a.id = '22222222-0000-4000-8000-0000000000b3'),
  'null'::jsonb, 'a map the hub has no page for links nothing');

select is(
  (select public.archive_moderation_row(a) - array['id', 'sha256', 'filename', 'kind', 'internal_name', 'version', 'shortname', 'author', 'size', 'listed_at', 'page', 'copies'] from public.archive as a where a.id = '22222222-0000-4000-8000-0000000000b1'),
  '{"uploader": "a1111111-0000-4000-8000-0000000000b1", "uploader_name": "Link Uploader"}'::jsonb, 'the row names its uploader by their profile name');
select is(
  (select public.archive_moderation_row(a) - array['id', 'sha256', 'filename', 'kind', 'internal_name', 'version', 'shortname', 'author', 'size', 'listed_at', 'page', 'copies'] from public.archive as a where a.id = '22222222-0000-4000-8000-0000000000b2'),
  '{"uploader": null, "uploader_name": null}'::jsonb, 'an upload whose account is gone has no uploader');

select * from finish();
rollback;
