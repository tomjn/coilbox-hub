-- What a moderator can do to the catalog by hand (issue #193).
--
-- Three separate jobs share this file because they share a database and nothing
-- else: clearing a map whose held facts are the wrong ones, editing the tags no
-- measurement produces, and merging two author keys that are one person.
--
-- Each one fails quietly if it is wrong. A clear that left the conflict records
-- behind would refuse the same map forever with nothing to show why. A re-ingest
-- that carried curated_tags away would undo a maintainer's work at the moment a
-- client happened to sync. A merge that the listing did not follow would look
-- recorded and change nothing anybody can see.
--
-- The submission harness below is map_submission.test.sql's, because what is
-- being proved here is what happens to a submission after a moderator has acted,
-- and the outcome has to come from the same function a client's submission goes
-- through rather than from a row written by hand.

begin;
select plan(26);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('99999999-9999-9999-9999-999999999999', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'client@example.test');

-- One entry, as the route normalises it.
create function pg_temp.entry(
  p_name text,
  p_hash text,
  p_version integer,
  p_author text default null,
  p_points jsonb default '{"start": [], "metal": [], "geo": []}'::jsonb
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
    'world_height_max', 890,
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

-- What one map's submission came to, with the slug and digest the hub would have
-- computed standing in as parameters.
create function pg_temp.outcome(
  p_entry jsonb,
  p_digest text,
  p_by uuid default '99999999-9999-9999-9999-999999999999'
) returns text language sql as $$
  select public.submit_map_facts(
    jsonb_build_array(jsonb_build_object(
      'entry', p_entry,
      'slug', 'slug-' || (p_entry ->> 'map_name'),
      'slug_alternative', 'slug-' || (p_entry ->> 'map_name') || '-abcd1234',
      'facts_digest', p_digest
    )),
    p_by
  ) -> 0 ->> 'outcome';
$$;

create function pg_temp.map_id(p_name text) returns uuid language sql as $$
  select m.id from public.map as m where m.map_name = p_name;
$$;

-- ## A map whose held facts are the wrong ones
--
-- The hub holds one set of bytes and every honest client reports another. The
-- held hash is compared before anything else, so every one of those submissions
-- is refused, and nothing but clearing the row can change that.

select pg_temp.outcome(
  pg_temp.entry(
    'Stuck 1.0', 'src-corrupt', 1, 'Beherith',
    '{"start": [{"x": 512, "z": 512, "y": null, "meta": null}], "metal": [], "geo": []}'::jsonb
  ),
  'digest-stuck-corrupt'
);

select is(
  pg_temp.outcome(pg_temp.entry('Stuck 1.0', 'src-real', 1), 'digest-stuck-real'),
  'conflict',
  'a second set of bytes under one canonical name is refused and written down'
);

select is(
  (select count(*)::int from public.map_source_conflict as c where c.map_id = pg_temp.map_id('Stuck 1.0')),
  1,
  'so the map has a disagreement recorded against it'
);

-- The guard, proved before the thing it guards. Without it this is a way to
-- delete any map in the catalog with the secret key.
select is(
  public.clear_map_facts(pg_temp.map_id('Comet 1.0')),
  false,
  'a map the hub does not hold is nothing to clear'
);

select pg_temp.outcome(pg_temp.entry('Settled 1.0', 'src-settled', 1), 'digest-settled');

select is(
  public.clear_map_facts(pg_temp.map_id('Settled 1.0')),
  false,
  'a map nobody has disagreed about is refused, so this is not a delete button'
);

select is(
  (select count(*)::int from public.map as m where m.map_name = 'Settled 1.0'),
  1,
  'and that map keeps its facts'
);

select is(
  public.clear_map_facts(pg_temp.map_id('Stuck 1.0')),
  true,
  'a map somebody has disagreed about is cleared'
);

select is(
  (select count(*)::int from public.map as m where m.map_name = 'Stuck 1.0'),
  0,
  'so the hub holds nothing about it'
);

select is(
  (select count(*)::int from public.map_source_conflict),
  0,
  'and the disagreement goes with it, because a conflict about a map nobody holds is a record of nothing'
);

select is(
  (select count(*)::int from public.map_point),
  0,
  'the points go too, which the cascade does rather than the function'
);

select is(
  (select count(*)::int from public.map_author as a where a.raw = 'Beherith'),
  0,
  'and so do the credits'
);

-- The whole point of clearing it. The next client to report the map is reporting
-- one the hub has never seen, so its facts are stored rather than refused.
select is(
  pg_temp.outcome(pg_temp.entry('Stuck 1.0', 'src-real', 1), 'digest-stuck-real'),
  'stored',
  'and the next submission stores the map rather than conflicting with it'
);

select is(
  (select source_hash from public.map as m where m.map_name = 'Stuck 1.0'),
  'src-real',
  'under the bytes the client actually has'
);

-- ## Curated tags
--
-- map.curated_tags is what a maintainer put there for what no measurement
-- captures. Ingest never writes the column, which is what makes the work safe to
-- do: nothing a client sends can undo it.

select pg_temp.outcome(pg_temp.entry('Tagged 1.0', 'src-tagged', 1), 'digest-tagged-v1');

update public.map as m
set curated_tags = array['asymmetric', 'chokepoint']
where m.map_name = 'Tagged 1.0';

select is(
  (select tags from public.map_listing as l where l.map_name = 'Tagged 1.0'),
  array['asymmetric', 'chokepoint', 'medium'],
  'a curated tag is merged with the derived ones, sorted and deduplicated'
);

select is(
  pg_temp.outcome(pg_temp.entry('Tagged 1.0', 'src-tagged', 2), 'digest-tagged-v2'),
  'replaced',
  'the same archive read by a newer extraction takes the row'
);

select is(
  (select curated_tags from public.map as m where m.map_name = 'Tagged 1.0'),
  array['asymmetric', 'chokepoint'],
  'and the curated tags survive it, because ingest never writes the column'
);

-- ## Two keys that are one person
--
-- The merge is a row in public.author_alias, and everything that reads an author
-- resolves through it, so recording one takes effect without a map being
-- resubmitted.

select pg_temp.outcome(pg_temp.entry('Merged A 1.0', 'src-merged-a', 1, 'Beherith'), 'digest-merged-a');
select pg_temp.outcome(pg_temp.entry('Merged B 1.0', 'src-merged-b', 1, 'bherith'), 'digest-merged-b');

select is(
  (select count(*)::int from public.map_browse as b where b.author_keys @> array['beherith']),
  1,
  'two spellings of one mapper are two authors until somebody says otherwise'
);

insert into public.author_alias (from_key, to_key, note)
values ('bherith', 'beherith', 'A typo, same person');

select is(
  (select count(*)::int from public.map_browse as b where b.author_keys @> array['beherith']),
  2,
  'a merge takes effect on the listing at once, with nothing resubmitted'
);

select is(
  (select maps from public.author_map_count as c where c.key = 'beherith'),
  2,
  'and the count the merge list is ordered by follows it'
);

select is(
  (select count(*)::int from public.author_map_count as c where c.key = 'bherith'),
  0,
  'while the key that was merged away counts for nothing, so it is off the list'
);

delete from public.author_alias as alias where alias.from_key = 'bherith';

select is(
  (select count(*)::int from public.map_browse as b where b.author_keys @> array['beherith']),
  1,
  'and removing the alias unmerges the two keys again'
);

-- An archive crediting one person twice is one map by them, which is exactly
-- what a merge produces and is why the count is over distinct maps.
select pg_temp.outcome(
  pg_temp.entry('Twice 1.0', 'src-twice', 1, 'Jools & [BAR]Jools'),
  'digest-twice'
);

select is(
  (select maps from public.author_map_count as c where c.key = 'jools'),
  1,
  'a map crediting one person twice is one map by them'
);

-- ## Who may do any of it
--
-- The moderation pages check is_moderator() per request and write through the
-- secret key. Nothing a browser holds reaches any of this.

reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"99999999-9999-9999-9999-999999999999","role":"authenticated"}';

select throws_ok(
  $$select public.clear_map_facts('00000000-0000-0000-0000-000000000000')$$,
  '42501',
  null,
  'a signed in account cannot clear a map'
);

select throws_ok(
  $$delete from public.author_alias$$,
  '42501',
  null,
  'nor unmerge two authors'
);

select throws_ok(
  $$update public.map set curated_tags = array['1v1']$$,
  '42501',
  null,
  'nor put a tag of its own on the catalog'
);

-- The counts are ordinary catalog reading, unlike the three above. Every row
-- behind them is already selectable by anybody holding the publishable key.
select is(
  (select count(*)::int from public.author_map_count as c where c.key = 'beherith'),
  1,
  'and reads the author counts, which publish nothing public.map_author does not'
);

reset role;
set local role anon;

select is(
  (select count(*)::int from public.author_map_count as c where c.key = 'beherith'),
  1,
  'as does a visitor with no account at all'
);

select * from finish();
rollback;
