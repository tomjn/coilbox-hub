-- Merging many author keys into one person and choosing their name (issue #442).
--
-- One mapper arrives under a dozen keys that no fold can join: `original from
-- Beherith`, `help from [teh]Beherith (mysterme@gmail.com)` and plain
-- `Beherith` are three keys. A moderator picks them off a list and merges them
-- in one go, and picks the name the merged author is shown under, because the
-- most common spelling can be `[teh]Beherith (mysterme@gmail.com)`.
--
-- The submission harness is map_moderation.test.sql's, so the credits come out
-- of the same function a client's submission goes through.

begin;
select plan(24);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values ('99999999-9999-9999-9999-999999999999', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'client@example.test');

create function pg_temp.entry(p_name text, p_author text) returns jsonb language sql as $$
  select jsonb_build_object(
    'map_name', p_name,
    'display_name', 'Display ' || p_name,
    'description', null,
    'map_version', null,
    'author', p_author,
    'archive_filename', null,
    'source_archive', p_name,
    'source_hash', 'src-' || p_name,
    'catalog_version', 1,
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
    'points', '{"start": [], "metal": [], "geo": []}'::jsonb
  );
$$;

create function pg_temp.submit(p_name text, p_author text) returns text language sql as $$
  select public.submit_map_facts(
    jsonb_build_array(jsonb_build_object(
      'entry', pg_temp.entry(p_name, p_author),
      'slug', 'slug-' || p_name,
      'slug_alternative', 'slug-' || p_name || '-abcd1234',
      'facts_digest', 'digest-' || p_name
    )),
    '99999999-9999-9999-9999-999999999999'
  ) -> 0 ->> 'outcome';
$$;

-- The most common spelling under `beherith` is the one with the email in it,
-- which is the name the owner saw and did not want.
select pg_temp.submit('Tagged A 1.0', '[teh]Beherith (mysterme@gmail.com)');
select pg_temp.submit('Tagged B 1.0', '[teh]Beherith (mysterme@gmail.com)');
select pg_temp.submit('Plain 1.0', 'Beherith');
select pg_temp.submit('Original 1.0', 'original from Beherith');
select pg_temp.submit('Help 1.0', 'help from [teh]Beherith (mysterme@gmail.com)');
select pg_temp.submit('Joint 1.0', 'original from Beherith/The_Yak');
select pg_temp.submit('Typo 1.0', 'bherith');
select pg_temp.submit('Other 1.0', 'Jools');

-- ## Every spelling under the key it counts as

select set_eq(
  $$select raw, credits from public.author_spelling where key = 'beherith'$$,
  $$values ('[teh]Beherith (mysterme@gmail.com)', 2), ('Beherith', 1)$$,
  'author_spelling lists each spelling under a key with how many credits it is on'
);

select is(
  (select name from public.author_display_name where key = 'beherith'),
  '[teh]Beherith (mysterme@gmail.com)',
  'with no name chosen, the most common spelling is still the name'
);

-- ## Many keys into one, with a chosen name

-- An older merge into a key that is about to be merged away. Merging that key
-- has to carry this one along, or it would point at an alias and count for
-- nobody.
insert into public.author_alias (from_key, to_key) values ('bhrith', 'bherith');

select is(
  public.merge_author_keys(
    'beherith',
    array['original from beherith', 'help from [teh]beherith', 'original from beherith/the_yak', 'bherith', 'beherith'],
    '  Beherith  ',
    'All Beherith'
  ),
  'merged',
  'several keys merge into one in a single call'
);

select set_eq(
  $$select from_key, to_key from public.author_alias$$,
  $$values
    ('original from beherith', 'beherith'),
    ('help from [teh]beherith', 'beherith'),
    ('original from beherith/the_yak', 'beherith'),
    ('bherith', 'beherith'),
    ('bhrith', 'beherith')$$,
  'every key points at the person, and an alias into a merged key is repointed so nothing chains'
);

select is(
  (select note from public.author_alias where from_key = 'bherith'),
  'All Beherith',
  'the note is recorded on every new alias'
);

select is(
  (select maps from public.author_map_count where key = 'beherith'),
  7,
  'the merged author counts every map credited under any of the keys'
);

select is(
  (select count(*)::int from public.author_map_count where key <> 'beherith' and key <> 'jools'),
  0,
  'and none of the merged keys is left on the list as an author of its own'
);

select is(
  (select name from public.author_display_name where key = 'beherith'),
  'Beherith',
  'the chosen name, trimmed, beats the most common spelling'
);

select is(
  (select author_names from public.map_browse where map_name = 'Original 1.0'),
  array['Beherith'],
  'and a map card shows it'
);

select is(
  public.map_facts(array['Help 1.0']) -> 0 -> 'facts' -> 'authors',
  '[{"key": "beherith", "name": "Beherith"}]'::jsonb,
  'as does the lookup the client reads'
);

select set_eq(
  $$select raw from public.author_spelling where key = 'beherith'$$,
  $$values ('[teh]Beherith (mysterme@gmail.com)'), ('Beherith'), ('original from Beherith'),
    ('help from [teh]Beherith (mysterme@gmail.com)'), ('original from Beherith/The_Yak'), ('bherith')$$,
  'the spellings follow the merge, so a moderator can pick any of them as the name'
);

-- ## Refusals

select is(
  public.merge_author_keys('bherith', array['jools'], null, null),
  'chained',
  'merging into a key that is itself merged is refused'
);

select is(
  (select count(*)::int from public.author_alias where from_key = 'jools'),
  0,
  'and writes nothing'
);

select is(
  public.merge_author_keys('jools', array['jools'], '', null),
  'refused',
  'a merge with nothing to merge and no name is refused'
);

select is(
  public.merge_author_keys('  ', array['jools'], null, null),
  'refused',
  'a blank key is not a person'
);

-- ## Renaming with nothing to merge

select is(
  public.merge_author_keys('jools', '{}', 'Jools the Mapper', null),
  'merged',
  'one key and a name renames that author'
);

select is(
  (select name from public.author_display_name where key = 'jools'),
  'Jools the Mapper',
  'and the new name is what they are shown under'
);

select is(
  public.merge_author_keys('jools', '{}', 'Jools', null),
  'merged',
  'renaming again replaces the name'
);

select is(
  (select name from public.author_display_name where key = 'jools'),
  'Jools',
  'rather than keeping the first one'
);

-- ## Unmerging

delete from public.author_alias where from_key = 'original from beherith';

select is(
  (select name from public.author_display_name where key = 'original from beherith'),
  'original from Beherith',
  'an unmerged key is its own author again, under its own spelling'
);

-- ## Who may do it

select ok(
  (select relrowsecurity from pg_class where oid = 'public.author_name'::regclass),
  'row level security is on for author_name'
);

set local role authenticated;
set local request.jwt.claims = '{"sub":"99999999-9999-9999-9999-999999999999","role":"authenticated"}';

select throws_ok(
  $$select public.merge_author_keys('jools', array['beherith'], null, null)$$,
  '42501',
  null,
  'a signed in account cannot merge authors'
);

select throws_ok(
  $$insert into public.author_name (key, name) values ('jools', 'Someone')$$,
  '42501',
  null,
  'nor name one'
);

reset role;
set local role anon;

select is(
  (select name from public.author_display_name where key = 'beherith'),
  'Beherith',
  'a visitor with no account reads the chosen name'
);

select * from finish();
rollback;
