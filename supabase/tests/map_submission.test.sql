-- What the hub does with a map's facts when a client sends them (issue #187).
--
-- public.submit_map_facts is where the outcome table lives, because deciding an
-- outcome means reading a row and acting on it in one transaction, and the
-- migration writes out why that cannot be split across a network. So this is
-- where the outcome table is proved, one row of it at a time, against real rows.
--
-- Every failure these rules can have is quiet. A submission read as unchanged
-- when the facts moved drops an improvement with nothing to show for it. Points
-- merged rather than replaced leave a metal spot the newer extraction knows is
-- not there, on a map that otherwise looks freshly written. A conflict recorded
-- twice fills a reviewer's table with one disagreement. None of them raises
-- anything on its own.
--
-- The parsing, the digest and the slug are the route's and are tested in
-- lib/api/mapSubmit.test.ts, lib/maps/facts.test.ts and lib/maps/slug.test.ts.
-- Everything here is handed the normalised entry those produce.
--
-- Grants are not tested here. table_privileges.test.sql asserts them directly
-- and map_access.test.sql covers the behaviour.

begin;
select plan(41);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('66666666-6666-6666-6666-666666666666', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'first@example.test'),
  ('77777777-7777-7777-7777-777777777777', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'second@example.test');

-- One entry, as the route normalises it: every optional field present, the
-- points in the order they will be stored in, and nothing the hub works out for
-- itself.
create function pg_temp.entry(
  p_name text,
  p_hash text,
  p_version integer,
  p_author text default null,
  p_points jsonb default '{"start": [], "metal": [], "geo": []}'::jsonb,
  p_height real default 890
) returns jsonb language sql as $$
  select jsonb_build_object(
    'map_name', p_name,
    'display_name', 'Display ' || p_name,
    'description', null,
    'map_version', null,
    'author', p_author,
    'archive_filename', null,
    'source_archive', p_name,
    'source_hash', p_hash,
    'catalog_version', p_version,
    'width_elmos', 6144,
    'height_elmos', 10240,
    'world_height_min', -120.5,
    'world_height_max', p_height,
    'min_wind', null,
    'max_wind', null,
    'tidal_strength', null,
    'void_water', null,
    'void_ground', null,
    'water_coverage', null,
    'appearance', '{}'::jsonb,
    'points', p_points
  );
$$;

-- One submission: the entry, the two slug candidates and the digest the hub
-- computed over the entry. The digest is a parameter here rather than something
-- this test computes, because it is the route's to compute and what matters
-- below is only whether two of them are equal.
create function pg_temp.submission(
  p_entry jsonb,
  p_digest text,
  p_slug text default null
) returns jsonb language sql as $$
  select jsonb_build_object(
    'entry', p_entry,
    'slug', coalesce(p_slug, 'slug-' || (p_entry ->> 'map_name')),
    'slug_alternative', coalesce(p_slug, 'slug-' || (p_entry ->> 'map_name')) || '-abcd1234',
    'facts_digest', p_digest
  );
$$;

-- What one map's submission came to.
create function pg_temp.outcome(p_submission jsonb, p_by uuid) returns text
language sql as $$
  select public.submit_map_facts(jsonb_build_array(p_submission), p_by) -> 0 ->> 'outcome';
$$;

-- What a batch came to, in the order it was sent.
create function pg_temp.outcomes(p_submissions jsonb, p_by uuid) returns text[]
language sql as $$
  select array_agg(result.value ->> 'outcome' order by result.ordinality)
  from jsonb_array_elements(public.submit_map_facts(p_submissions, p_by))
    with ordinality as result(value, ordinality);
$$;

-- ## A map the hub has never seen

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry(
        'Comet Catcher Remake 1.8', 'src-comet', 3, 'Beherith & Icexuick',
        '{"start": [{"x": 512, "z": 512, "y": null, "meta": null}],
          "metal": [{"x": 1024, "z": 2048, "y": null, "meta": {"amount": 2, "radius": 48}},
                    {"x": 3000, "z": 400, "y": null, "meta": {"amount": 1, "radius": 48}}],
          "geo": [{"x": 3072, "z": 4096, "y": null, "meta": {"feature": "geovent"}}]}'::jsonb
      ),
      'digest-comet-v3'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'stored',
  'a map the hub holds nothing for is stored'
);

select is(
  (select catalog_version from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  3,
  'and the row records which extraction read the archive'
);

select is(
  (select slug from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  'slug-Comet Catcher Remake 1.8',
  'under the slug the hub computed, which a client never sends'
);

select is(
  (select submitted_by from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  '66666666-6666-6666-6666-666666666666'::uuid,
  'and records who submitted it'
);

select is(
  (
    select count(*)::int from public.map_point as p
    join public.map as m on m.id = p.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  4,
  'every point the entry carried is a row, whatever its kind'
);

select is(
  (
    select p.meta from public.map_point as p
    join public.map as m on m.id = p.map_id
    where m.map_name = 'Comet Catcher Remake 1.8' and p.kind = 'geo' and p.ordinal = 0
  ),
  '{"feature": "geovent"}'::jsonb,
  'and keeps whatever its kind carries'
);

-- The credits, split and keyed by the database rather than by the route, which
-- is what stops the hub and every read path drifting apart about what a name
-- keys to.
select is(
  (
    select array_agg(a.key order by a.credit_index) from public.map_author as a
    join public.map as m on m.id = a.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  array['beherith', 'icexuick'],
  'a credit naming two people is two authors, each under its own key'
);

select is(
  (
    select array_agg(a.raw order by a.credit_index) from public.map_author as a
    join public.map as m on m.id = a.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  array['Beherith', 'Icexuick'],
  'and each keeps the spelling the archive gave it'
);

-- A maintainer's merge is applied on the way in, so a map lands under the person
-- rather than under the spelling.
insert into public.author_alias (from_key, to_key, note)
values ('jools', 'joolsmaster', 'Same person');

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry('Tangerine 1.1', 'src-tangerine', 1, '[BAR] Jools & [XYZ]'),
      'digest-tangerine-v1'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'stored',
  'a second map is its own row'
);

select is(
  (
    select array_agg(a.key) from public.map_author as a
    join public.map as m on m.id = a.map_id
    where m.map_name = 'Tangerine 1.1'
  ),
  array['joolsmaster'],
  'a tagged credit keys to the person, and a merge the maintainer recorded is applied'
);

select is(
  (
    select count(*)::int from public.map_author as a
    join public.map as m on m.id = a.map_id
    where m.map_name = 'Tangerine 1.1'
  ),
  1,
  'and a credit that is nothing but a clan tag names nobody, so it is dropped'
);

-- ## The same entry again
--
-- now() is the transaction's clock, so seen_at is pushed back by hand first.
-- Without that the assertion would pass on a row nothing had touched.
update public.map as m
set seen_at = now() - interval '2 days'
where m.map_name = 'Comet Catcher Remake 1.8';

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry('Comet Catcher Remake 1.8', 'src-comet', 3),
      'digest-comet-v3'
    ),
    '77777777-7777-7777-7777-777777777777'
  ),
  'unchanged',
  'the same archive, the same extraction and the same facts change nothing'
);

select ok(
  (select seen_at from public.map as m where m.map_name = 'Comet Catcher Remake 1.8') = now(),
  'and the only thing that moves is when a client last reported the map present'
);

select is(
  (select submitted_by from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  '66666666-6666-6666-6666-666666666666'::uuid,
  'a resubmission that changes nothing does not take the map over either'
);

-- ## A newer extraction of the same archive

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry(
        'Comet Catcher Remake 1.8', 'src-comet', 4, 'Beherith',
        '{"start": [{"x": 512, "z": 512, "y": null, "meta": null}],
          "metal": [{"x": 1024, "z": 2048, "y": null, "meta": {"amount": 2, "radius": 48}}],
          "geo": []}'::jsonb
      ),
      'digest-comet-v4'
    ),
    '77777777-7777-7777-7777-777777777777'
  ),
  'replaced',
  'the same archive read by a newer extraction takes the row'
);

select is(
  (select catalog_version from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  4,
  'and the row says which extraction it came from now'
);

select is(
  (select facts_digest from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  'digest-comet-v4',
  'and holds the digest over the facts it now holds'
);

-- Replaced wholesale rather than merged, which is the whole reason service_role
-- holds delete on this table. A newer extraction can drop a metal spot the older
-- one invented, and a merge could only ever add.
select is(
  (
    select count(*)::int from public.map_point as p
    join public.map as m on m.id = p.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  2,
  'the points are replaced wholesale, so a spot the newer read does not have is gone'
);

select is(
  (
    select count(*)::int from public.map_point as p
    join public.map as m on m.id = p.map_id
    where m.map_name = 'Comet Catcher Remake 1.8' and p.kind = 'geo'
  ),
  0,
  'including a whole kind of point that the newer read found none of'
);

select is(
  (
    select array_agg(a.key) from public.map_author as a
    join public.map as m on m.id = a.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  array['beherith'],
  'and the credits are replaced too, so a co-author the older read misparsed is gone'
);

select is(
  (select submitted_by from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  '77777777-7777-7777-7777-777777777777'::uuid,
  'anybody may improve a map, because facts are reproducible and there is no owner'
);

select is(
  (select slug from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  'slug-Comet Catcher Remake 1.8',
  'and the slug does not move, so every link to the map still works'
);

-- ## An older extraction of the same archive

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry('Comet Catcher Remake 1.8', 'src-comet', 2),
      'digest-comet-v2'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'unchanged',
  'an older extraction of the same archive cannot talk the catalog backwards'
);

select is(
  (
    select array[catalog_version::text, facts_digest]
    from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  array['4', 'digest-comet-v4'],
  'and the stored row does not move'
);

select is(
  (
    select count(*)::int from public.map_point as p
    join public.map as m on m.id = p.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  2,
  'nor do its points'
);

-- ## The same archive and extraction, different facts
--
-- Extraction is deterministic, so two clients reading one archive with one
-- version of the code cannot honestly disagree, and one of the two readings is
-- wrong.

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry(
        'Comet Catcher Remake 1.8', 'src-comet', 4, null,
        '{"start": [], "metal": [], "geo": []}'::jsonb, 1200
      ),
      'digest-comet-v4-different'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'conflict',
  'the same archive and extraction reporting different facts is refused'
);

select is(
  (select facts_digest from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  'digest-comet-v4',
  'and nothing about the held row moves'
);

-- No conflict row, and that is the table's rule rather than an omission.
-- map_source_conflict records a disagreement about an archive's bytes, and
-- map_source_conflict_differs_check refuses a row whose two hashes agree, which
-- these do. The disagreement here is about the reading.
select is(
  (
    select count(*)::int from public.map_source_conflict as c
    join public.map as m on m.id = c.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  0,
  'and no conflict is recorded, because the two sides agree about the archive'
);

-- ## A different archive under one name

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry('Comet Catcher Remake 1.8', 'src-modified', 4),
      'digest-comet-modified'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'conflict',
  'two different sets of bytes under one canonical name is never a new release'
);

select is(
  (
    select array[c.held_source_hash, c.reported_source_hash]
    from public.map_source_conflict as c
    join public.map as m on m.id = c.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  array['src-comet', 'src-modified'],
  'and the disagreement is written down with both hashes on it'
);

-- A client that loops on a refused submission leaves one record rather than a
-- table full of them, which is what map_source_conflict_report_idx is for.
select pg_temp.outcome(
  pg_temp.submission(
    pg_temp.entry('Comet Catcher Remake 1.8', 'src-modified', 4),
    'digest-comet-modified'
  ),
  '66666666-6666-6666-6666-666666666666'
);

select pg_temp.outcome(
  pg_temp.submission(
    pg_temp.entry('Comet Catcher Remake 1.8', 'src-modified', 4),
    'digest-comet-modified'
  ),
  '77777777-7777-7777-7777-777777777777'
);

select is(
  (
    select count(*)::int from public.map_source_conflict as c
    join public.map as m on m.id = c.map_id
    where m.map_name = 'Comet Catcher Remake 1.8'
  ),
  1,
  'however many times it is retried, and by however many accounts'
);

select is(
  (select source_hash from public.map as m where m.map_name = 'Comet Catcher Remake 1.8'),
  'src-comet',
  'and the map the hub already had is left exactly where it was'
);

-- ## A batch, where one map is refused and the others are not
--
-- The reason the outcome is per map inside a 200 rather than a status for the
-- request. A client with fifty maps and one anomaly should not have to find the
-- anomaly before any of the other forty nine can be stored.

select is(
  pg_temp.outcomes(
    jsonb_build_array(
      pg_temp.submission(pg_temp.entry('Alpha 1.0', 'src-alpha', 1), 'digest-alpha'),
      pg_temp.submission(
        pg_temp.entry('Comet Catcher Remake 1.8', 'src-modified', 4), 'digest-x'
      ),
      pg_temp.submission(pg_temp.entry('Gamma 1.0', 'src-gamma', 1), 'digest-gamma')
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  array['stored', 'conflict', 'stored'],
  'one conflicting map in a batch of three leaves the other two stored'
);

select is(
  (select count(*)::int from public.map as m where m.map_name in ('Alpha 1.0', 'Gamma 1.0')),
  2,
  'and both of them really are rows'
);

-- ## An entry the tables refuse
--
-- The route refuses everything it can name, so what reaches here is what only
-- Postgres can catch. It costs that entry and nothing else, because each one
-- runs in a subtransaction of its own.

select is(
  pg_temp.outcomes(
    jsonb_build_array(
      pg_temp.submission(
        pg_temp.entry('Delta 1.0', 'src-delta', 1, repeat('A', 300)), 'digest-delta'
      ),
      pg_temp.submission(pg_temp.entry('Epsilon 1.0', 'src-epsilon', 1), 'digest-epsilon')
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  array['refused', 'stored'],
  'an entry the tables refuse costs that entry alone'
);

select is(
  (select count(*)::int from public.map as m where m.map_name = 'Delta 1.0'),
  0,
  'and the refused entry leaves nothing behind, not even the row it started to write'
);

-- ## Two names, one slug
--
-- Two different canonical names can render to one URL, and the unique index
-- would otherwise refuse the second map its facts forever.

select pg_temp.outcome(
  pg_temp.submission(pg_temp.entry('Zeta 1.0', 'src-zeta', 1), 'digest-zeta', 'zeta-1-0'),
  '66666666-6666-6666-6666-666666666666'
);

select is(
  pg_temp.outcome(
    pg_temp.submission(
      pg_temp.entry('Zeta_1.0', 'src-zeta-other', 1), 'digest-zeta-other', 'zeta-1-0'
    ),
    '66666666-6666-6666-6666-666666666666'
  ),
  'stored',
  'a second map whose name renders to a slug another map holds is still stored'
);

select is(
  (select slug from public.map as m where m.map_name = 'Zeta_1.0'),
  'zeta-1-0-abcd1234',
  'under the alternative slug, which is derived from its own name'
);

-- ## The rate limit
--
-- Counted per account per hour, on rows inserted, which is the shape
-- 20260809181909_publish_rate_limit.sql sets out. A third account is used
-- because the count has to be exact and the two above have already stored maps.
--
-- The ceiling is above a full first sync on purpose, so this fills the window by
-- hand rather than by submitting five thousand maps through the route's path.
-- The rows are inserted directly, which is what the trigger counts either way.

insert into auth.users (id, instance_id, aud, role, email)
values ('88888888-8888-8888-8888-888888888888', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'busy@example.test');

insert into public.map (
  map_name, slug, width_elmos, height_elmos, world_height_min, world_height_max,
  source_hash, source_archive, catalog_version, facts_digest, submitted_by
)
select
  'Filler ' || n, 'filler-' || n, 1024, 1024, 0, 320,
  'src-filler', 'filler.sd7', 1, 'digest-filler', '88888888-8888-8888-8888-888888888888'
from generate_series(1, 4999) as n;

select is(
  pg_temp.outcome(
    pg_temp.submission(pg_temp.entry('Under 1.0', 'src-under', 1), 'digest-under'),
    '88888888-8888-8888-8888-888888888888'
  ),
  'stored',
  'an account under the ceiling stores its map'
);

-- Raised rather than reported as a refusal, because it is the account's problem
-- and not this map's. The route answers 429 for the whole request, and the batch
-- has written nothing by the time the client sees it.
select throws_ok(
  $$select public.submit_map_facts(
      jsonb_build_array(pg_temp.submission(
        pg_temp.entry('Over 1.0', 'src-over', 1), 'digest-over'
      )),
      '88888888-8888-8888-8888-888888888888'
    )$$,
  '53400',
  'Too many maps submitted in the last hour. Try again later.',
  'and the next one past the ceiling is refused for the whole request'
);

select is(
  (select count(*)::int from public.map as m where m.map_name = 'Over 1.0'),
  0,
  'so nothing of it is written'
);

select * from finish();
rollback;
