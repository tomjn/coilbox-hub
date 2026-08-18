-- Who may read and write the map catalog (issue #182), run as the roles
-- PostgREST actually uses. `anon` is a visitor with no session, `authenticated`
-- is somebody signed in with Discord, and both of them hold the publishable
-- key, which is inlined into the browser bundle and served as JSON by #48.
-- `service_role` is the Vercel route and nothing else.
--
-- The catalog is the opposite of public.asset in what it hides, which is
-- nothing: a row describes a map that is already published everywhere else, so
-- every reader gets every row. What the layers are for here is the writing.
-- slug, facts_digest and the author keys are computed by the route, and a row
-- written straight through PostgREST would have none of them: unreachable by
-- URL, permanently reading as unchanged facts, and credited to nobody.
--
-- A grant and a policy are two layers and either one shut is enough, which is
-- why table_privileges.test.sql asserts the grants directly as well. #59 found
-- production holding grants these migrations never wrote, and behavioural tests
-- alone cannot see that.

begin;
select plan(27);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('55555555-5555-5555-5555-555555555555', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'submitter@example.test');

-- One map with everything hanging off it, so each table has a row to be read or
-- refused. Every count below names its own rows rather than counting the table
-- (issue #140), so a machine where somebody has exercised a route still fails
-- on the access rules rather than on the leftover row.
insert into public.map (id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest, submitted_by)
values ('0f8fad5b-0002-4000-8000-000000000001', 'Comet Catcher Remake 1.8', 'comet-catcher-remake-1-8', 8192, 8192, -40.5, 320.25, 'src-comet', 'comet_catcher_remake_1.8.sd7', 1, 'digest-comet', '55555555-5555-5555-5555-555555555555');

insert into public.map_point (map_id, kind, ordinal, x, z)
values
  ('0f8fad5b-0002-4000-8000-000000000001', 'start', 0, 512, 512),
  ('0f8fad5b-0002-4000-8000-000000000001', 'start', 1, 7680, 7680),
  ('0f8fad5b-0002-4000-8000-000000000001', 'metal', 0, 1024, 980);

insert into public.map_author (map_id, credit_index, raw, key)
values ('0f8fad5b-0002-4000-8000-000000000001', 0, '[TA]Bob', 'bob');

insert into public.author_alias (from_key, to_key, set_by)
values ('bob', 'bobtheta', '55555555-5555-5555-5555-555555555555');

insert into public.map_mirror_host (name, url_template)
values ('Example mirror', 'https://mirror.example.test/maps/{archive_filename}');

insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash, reported_by)
values ('0f8fad5b-0002-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-other', '55555555-5555-5555-5555-555555555555');

-- A visitor with no account at all.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select count(*) from public.map where slug = 'comet-catcher-remake-1-8')::int, 1,
  'a visitor reads a map'
);

select is(
  (select count(*) from public.map_point
    where map_id = '0f8fad5b-0002-4000-8000-000000000001')::int, 3,
  'and every point on it'
);

select is(
  (select count(*) from public.map_author
    where map_id = '0f8fad5b-0002-4000-8000-000000000001')::int, 1,
  'and who made it'
);

select is(
  (select count(*) from public.author_alias where from_key = 'bob')::int, 1,
  'and the merges a maintainer recorded, which a listing needs to group by author at all'
);

select is(
  (select count(*) from public.map_mirror_host where name = 'Example mirror')::int, 1,
  'and where to download it from'
);

-- A refusal rather than a write that affects nothing. A silently empty write
-- looks like success to the caller, and the caller here is somebody probing.
select throws_ok(
  $$insert into public.map (map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('Made Up 1.0', 'made-up-1-0', 1024, 1024, 0, 320, 'src-x', 'x.sd7', 1, 'digest-x')$$,
  '42501',
  null,
  'a visitor cannot write itself a map, since the route is what computes the slug and the digest'
);

select throws_ok(
  $$update public.map set display_name = 'Something Else'$$,
  '42501',
  null,
  'nor rename one'
);

select throws_ok(
  $$delete from public.map$$,
  '42501',
  null,
  'nor remove one'
);

select throws_ok(
  $$insert into public.map_point (map_id, kind, ordinal, x, z)
    values ('0f8fad5b-0002-4000-8000-000000000001', 'metal', 99, 1, 1)$$,
  '42501',
  null,
  'nor put a metal spot on somebody else''s map'
);

select throws_ok(
  $$insert into public.author_alias (from_key, to_key) values ('jools', 'somebody-else')$$,
  '42501',
  null,
  'nor decide that two mappers are one person'
);

-- The one row in the catalog that is worth forging: a mirror template is a URL
-- the hub tells people to download from.
select throws_ok(
  $$update public.map_mirror_host set url_template = 'https://attacker.example.test/{archive_filename}'$$,
  '42501',
  null,
  'and cannot point a download link at a server of its own'
);

select throws_ok(
  $$select count(*) from public.map_source_conflict$$,
  '42501',
  null,
  'a disagreement about what an archive contains is not served to a browser'
);

-- Signed in, and the account that submitted the map.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"55555555-5555-5555-5555-555555555555","role":"authenticated"}';

select is(
  (select count(*) from public.map where slug = 'comet-catcher-remake-1-8')::int, 1,
  'an account reads exactly what a visitor reads, because there is nothing here to hide'
);

select throws_ok(
  $$update public.map set water_coverage = 0.9 where slug = 'comet-catcher-remake-1-8'$$,
  '42501',
  null,
  'and cannot correct the facts on its own submission, because a correction is a resubmission through the route'
);

select throws_ok(
  $$select count(*) from public.map_source_conflict$$,
  '42501',
  null,
  'a signed in account cannot read who reported what either'
);

select throws_ok(
  $$insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0002-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-typed')$$,
  '42501',
  null,
  'nor manufacture a disagreement about a map it has never opened'
);

-- The Vercel route, which holds the secret key and nothing in a browser does.
reset role;
set local role service_role;

select lives_ok(
  $$insert into public.map (id, map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max, source_hash, source_archive, catalog_version, facts_digest)
    values ('0f8fad5b-0002-4000-8000-000000000002', 'Tangerine 1.1', 'tangerine-1-1', 4096, 4096, 0, 320, 'src-t', 'tangerine_1.1.sd7', 1, 'digest-t')$$,
  'the route writes a map, which is the only way one is ever written'
);

select lives_ok(
  $$update public.map set seen_at = now() where slug = 'tangerine-1-1'$$,
  'and records that a client saw it again'
);

-- A resubmission replaces a map's points rather than editing them, so the route
-- has to be able to take the old set away. Without delete a map that loses a
-- metal spot keeps it forever, and nothing on the row shows it is stale.
select lives_ok(
  $$delete from public.map_point where map_id = '0f8fad5b-0002-4000-8000-000000000001'$$,
  'and replaces the whole set of points on a map'
);

select lives_ok(
  $$delete from public.map_author where map_id = '0f8fad5b-0002-4000-8000-000000000001'$$,
  'and its credits, since a co-author can be dropped between one release and the next'
);

select throws_ok(
  $$delete from public.map where slug = 'tangerine-1-1'$$,
  '42501',
  null,
  'the route cannot delete a map, because a superseded map is still a map that existed'
);

select is(
  (select count(*) from public.map_source_conflict
    where map_id = '0f8fad5b-0002-4000-8000-000000000001')::int, 1,
  'the secret key reads the disagreements, which is how a reviewer knows which map to look at'
);

select lives_ok(
  $$insert into public.map_source_conflict (map_id, source_archive, held_source_hash, reported_source_hash)
    values ('0f8fad5b-0002-4000-8000-000000000001', 'comet_catcher_remake_1.8.sd7', 'src-comet', 'src-third')$$,
  'and records one, which is the only thing that ever writes this table'
);

-- The policies themselves, named and shaped, so a second one added later
-- without a test is visible here rather than in production.
reset role;

select is(
  (select array_agg(polname order by polname) from pg_policy
    where polrelid in (
      'public.map'::regclass, 'public.map_point'::regclass,
      'public.map_author'::regclass, 'public.author_alias'::regclass,
      'public.map_mirror_host'::regclass)),
  ARRAY['author_alias_read_all', 'map_author_read_all', 'map_mirror_host_read_all',
        'map_point_read_all', 'map_read_all']::name[],
  'one policy on each of the five public tables and no others'
);

select is(
  (select array_agg(distinct polcmd) from pg_policy
    where polrelid in (
      'public.map'::regclass, 'public.map_point'::regclass,
      'public.map_author'::regclass, 'public.author_alias'::regclass,
      'public.map_mirror_host'::regclass)),
  ARRAY['r'::"char"],
  'and every one of them is a read policy, so nothing they do can let a write through'
);

select is(
  (select count(*) from pg_policy
    where polrelid = 'public.map_source_conflict'::regclass)::int, 0,
  'no policy at all on the conflicts, so the publishable key reads nothing whatever the grants say'
);

select is(
  (select count(*) from pg_class
    where oid in (
      'public.map'::regclass, 'public.map_point'::regclass,
      'public.map_author'::regclass, 'public.author_alias'::regclass,
      'public.map_mirror_host'::regclass, 'public.map_source_conflict'::regclass)
    and relrowsecurity)::int,
  6,
  'row level security is on for all six tables, so no grant is the only thing standing there'
);

select * from finish();
rollback;
