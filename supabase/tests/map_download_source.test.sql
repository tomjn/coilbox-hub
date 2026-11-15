-- Who may write a map's download sources, what the table refuses, and what
-- map_facts hands the lookup route.
--
-- Run as the roles PostgREST uses, because a grant and a policy are separate
-- layers and a test run as the database owner proves neither.

begin;
select plan(17);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'map-owner@example.test'),
  ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'stranger@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test'),
  ('dddddddd-dddd-dddd-dddd-dddddddddddd', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'author-owner@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.map (
  id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  appearance, curated_tags, source_hash, source_archive, catalog_version, facts_digest, owner_user_id
)
values
  ('11111111-0000-4000-8000-000000000001', 'Owned 1.0', 'owned-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-owned', 'owned_1.0.sd7', 1, 'digest-owned', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
  ('11111111-0000-4000-8000-000000000002', 'Bobs Rift 1.0', 'bobs-rift-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-rift', 'bobs_rift_1.0.sd7', 1, 'digest-rift', null),
  ('11111111-0000-4000-8000-000000000003', 'Orphan 1.0', 'orphan-1-0', 4096, 4096, 0, 100,
    '{}'::jsonb, '{}', 'src-orphan', 'orphan_1.0.sd7', 1, 'digest-orphan', null);

-- Bob's Rift was credited to "bob", which a moderator later merged into
-- "bobtheta". The author owner was granted the old key, before the merge.
insert into public.map_author (map_id, credit_index, raw, key)
values ('11111111-0000-4000-8000-000000000002', 0, 'Bob', 'bob');
insert into public.author_alias (from_key, to_key) values ('bob', 'bobtheta');
insert into public.author_owner (key, user_id) values ('bob', 'dddddddd-dddd-dddd-dddd-dddddddddddd');

-- ## What the table refuses

select throws_ok(
  $$insert into public.map_download_source (map_id, kind, value)
    values ('11111111-0000-4000-8000-000000000001', 'rapid', 'owned:stable')$$,
  '23514', null,
  'a rapid source is refused'
);

select throws_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename)
    values ('11111111-0000-4000-8000-000000000001', 'github', 'owner/repo', 'owned.sd7')$$,
  '23514', null,
  'a filename on a github source is refused'
);

select throws_ok(
  $$insert into public.map_download_source (map_id, kind, value, asset)
    values ('11111111-0000-4000-8000-000000000001', 'url', 'https://example.test/owned.sd7', 'owned')$$,
  '23514', null,
  'an asset on a url source is refused'
);

-- ## The map's owner

set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select lives_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename, asset, sort_order)
    values ('11111111-0000-4000-8000-000000000001', 'url', 'https://example.test/owned.sd7', 'owned_1.0.sd7', null, 1),
           ('11111111-0000-4000-8000-000000000001', 'github', 'someone/owned-map', null, 'owned', 0)$$,
  'the map''s owner may write its sources'
);

select throws_ok(
  $$update public.map_download_source set value = 'https://example.test/other.sd7'$$,
  '42501', null,
  'nobody updates a source, a save replaces the list'
);

select throws_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename)
    values ('11111111-0000-4000-8000-000000000002', 'url', 'https://example.test/rift.sd7', 'rift.sd7')$$,
  '42501', null,
  'owning one map does not reach another'
);

select is(public.can_edit_map('11111111-0000-4000-8000-000000000001'), true, 'can_edit_map says yes to the map''s owner');

-- ## The author's owner, through a merge

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"dddddddd-dddd-dddd-dddd-dddddddddddd","role":"authenticated"}';

select lives_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename)
    values ('11111111-0000-4000-8000-000000000002', 'url', 'https://example.test/rift.sd7', 'bobs_rift_1.0.sd7')$$,
  'an owner of the credited author may write, even after the author was merged'
);

select is(public.can_edit_map('11111111-0000-4000-8000-000000000003'), false, 'an author owner cannot edit a map by somebody else');

-- ## A stranger

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb","role":"authenticated"}';

select throws_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename)
    values ('11111111-0000-4000-8000-000000000003', 'url', 'https://example.test/orphan.sd7', 'orphan.sd7')$$,
  '42501', null,
  'a stranger may not write a source'
);

-- The policy filters every row out, so this runs and removes nothing. The
-- count anyone reads below proves it.
select lives_ok(
  $$delete from public.map_download_source$$,
  'a stranger''s delete reaches no rows'
);

select throws_ok(
  $$select * from public.author_owner$$,
  '42501', null,
  'a browser cannot read who owns an author'
);

-- ## A moderator

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"cccccccc-cccc-cccc-cccc-cccccccccccc","role":"authenticated"}';

select lives_ok(
  $$insert into public.map_download_source (map_id, kind, value, filename)
    values ('11111111-0000-4000-8000-000000000003', 'url', 'https://example.test/orphan.sd7', 'orphan_1.0.sd7')$$,
  'a moderator may write any map''s sources'
);

-- ## Anyone reads

reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*)::integer from public.map_download_source),
  4,
  'anyone may read the sources'
);

reset role;

-- ## What the lookup route gets

select is(
  (select entry.value -> 'facts' -> 'downloads'
   from jsonb_array_elements(public.map_facts(array['Owned 1.0'])) as entry(value)),
  '[{"kind": "github", "value": "someone/owned-map", "asset": "owned"},
    {"kind": "url", "value": "https://example.test/owned.sd7", "filename": "owned_1.0.sd7"}]'::jsonb,
  'map_facts lists the sources in order, without the keys a kind does not use'
);

select ok(
  (select not (entry.value -> 'facts' ? 'owner_user_id')
   from jsonb_array_elements(public.map_facts(array['Owned 1.0'])) as entry(value)),
  'map_facts does not publish the owner'
);

delete from public.map where id = '11111111-0000-4000-8000-000000000003';
select is(
  (select count(*)::integer from public.map_download_source where map_id = '11111111-0000-4000-8000-000000000003'),
  0,
  'deleting a map deletes its sources'
);

select * from finish();
rollback;
