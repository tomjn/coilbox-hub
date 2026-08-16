-- public.asset_licence records what is known about a subject's redistribution
-- terms (issue #97). It no longer decides whether an upload is accepted, which
-- is #167: it refused every game nobody had researched, and moderation is what
-- handles a picture that should not be published. What this file guards is the
-- quality of the record, since a moderator reading a wrong row is worse off
-- than one reading no row.
--
-- Two properties matter more than the rest and are asserted first: a row grants
-- nothing by existing, and saying yes has to say what the yes rests on.

begin;
select plan(32);

create extension if not exists pgtap with schema extensions;

-- What the migrations permit, named subject by subject. This used to assert
-- that they permitted nothing at all, which was true until the maintainer
-- decided on 2026-08-14 (issue #121) and is a weaker property than the one that
-- actually matters: that the set of things a fresh database will publish is a
-- list somebody wrote down on purpose. Stated as a list, so a fourth subject
-- appearing in a migration fails here and has to be argued for in a diff rather
-- than noticed in production. Checked before the rows this file adds of its own.
select bag_eq(
  $$select coalesce(game, map_name, case when all_maps then '(every map)' end)
      from public.asset_licence
      where redistribute_extracted = 'allowed' or redistribute_rendered = 'allowed'$$,
  $$values ('BYAR'), ('BA'), ('XTA'), ('(every map)')$$,
  'the migrations permit exactly the four subjects the maintainer ruled on'
);

-- And none of those four rests on a licence. Two of the games state nothing,
-- the third states an ND term that would refuse the renders, and maps have no
-- central licence at all. If one of these ever loses its `decision` the row
-- stops being defensible, whatever the licence column happens to say.
select is(
  (select count(*) from public.asset_licence
    where (redistribute_extracted = 'allowed' or redistribute_rendered = 'allowed')
      and decision is null)::int,
  0,
  'nothing the migrations permit rests on a licence grant alone'
);

-- The map default is the row it claims to be. A lookup that finds nothing for a
-- map name reads this instead, so it is what the record says about almost every
-- map in the collection.
select is(
  (select count(*) from public.asset_licence where all_maps)::int, 1,
  'exactly one row answers for every map without one of its own'
);

-- A row inserted to record a licence permits nothing until somebody says so.
-- Everything downstream reads these two columns and nothing else, so a default
-- of `allowed` here would publish a corpus on the strength of a migration.
select lives_ok(
  $$insert into public.asset_licence (game, licence, licence_url, checked_at, checked_by)
    values ('TEST', 'MIT', 'https://example.test/licence', now(), 'pgtap')$$,
  'a game licence is recorded against the modinfo shortname'
);

select is(
  (select redistribute_extracted from public.asset_licence where game = 'TEST'), 'unknown',
  'recording a licence does not by itself permit redistributing extracted images'
);

select is(
  (select redistribute_rendered from public.asset_licence where game = 'TEST'), 'unknown',
  'and does not by itself permit publishing renders'
);

-- Saying yes with nothing behind it is the row this table exists to prevent.
select throws_ok(
  $$insert into public.asset_licence (game, checked_by, redistribute_extracted)
    values ('NOEV', 'pgtap', 'allowed')$$,
  '23514',
  null,
  'permitting extraction without recording what the permission rests on is refused'
);

select throws_ok(
  $$insert into public.asset_licence (game, checked_by, redistribute_rendered)
    values ('NOEV', 'pgtap', 'allowed')$$,
  '23514',
  null,
  'permitting renders without recording what the permission rests on is refused'
);

-- The widening (issue #121). A licence is one basis for a yes and is no longer
-- the only one, because two of the three games state nothing and maps have no
-- central licence at all. The alternative was writing a permissive `licence`
-- the research never found, which would have put a lie in the one column a
-- takedown request gets answered from. So the yes may cite a decision instead,
-- and `licence` stays null, which is the truth.
select lives_ok(
  $$insert into public.asset_licence (game, checked_by, decision, decided_at, redistribute_extracted, redistribute_rendered)
    values ('DECIDED', 'pgtap', 'maintainer decision, the community already does this', now(), 'allowed', 'allowed')$$,
  'a decision is a basis for permitting redistribution, with no licence found'
);

select is(
  (select licence from public.asset_licence where game = 'DECIDED'), null::text,
  'and permitting it did not require inventing a licence to point at'
);

-- A decision that cannot say when it was made ages invisibly, which is the
-- failure `checked_at` exists to prevent for research. Both or neither.
select throws_ok(
  $$insert into public.asset_licence (game, checked_by, decision) values ('UNDATED', 'pgtap', 'somebody said yes once')$$,
  '23514',
  null,
  'a decision without a date is refused'
);

select throws_ok(
  $$insert into public.asset_licence (game, checked_by, decided_at) values ('UNDATED', 'pgtap', now())$$,
  '23514',
  null,
  'and a date with no decision under it is refused too'
);

-- Refusing to publish harms nobody, so a no needs no citation.
select lives_ok(
  $$insert into public.asset_licence (game, checked_by, redistribute_extracted, redistribute_rendered, notes)
    values ('NOEV', 'pgtap', 'denied', 'denied', 'no licence file anywhere in the tree')$$,
  'refusing needs no evidence, because refusing publishes nothing'
);

-- The distinction the issue turns on. A render is a derivative work, and a
-- licence that plainly allows passing the shipped buildpic on does not always
-- cover generating a new image from the model. One flag could not say this.
select lives_ok(
  $$insert into public.asset_licence (game, licence, licence_url, checked_by, redistribute_extracted, redistribute_rendered)
    values ('SPLIT', 'CC-BY-NC-SA-4.0', 'https://example.test/split', 'pgtap', 'allowed', 'denied')$$,
  'extraction can be permitted while renders are refused'
);

select throws_ok(
  $$update public.asset_licence set redistribute_rendered = 'maybe' where game = 'SPLIT'$$,
  '23514',
  null,
  'a permission outside the three states is refused'
);

-- Who looked is half of what makes the record defensible later, so it is not
-- optional. When is defaulted rather than required, since now() is the truth
-- for anything being written.
select throws_ok(
  $$insert into public.asset_licence (game) values ('WHO')$$,
  '23502',
  null,
  'a record that cannot say who checked it is refused'
);

select isnt(
  (select checked_at from public.asset_licence where game = 'SPLIT'), null,
  'when it was checked is filled in even when the writer forgets'
);

-- The map side. Keyed on the full canonical name, with no game in it, because
-- the same map archive is used across BAR, XTA and BA.
select lives_ok(
  $$insert into public.asset_licence (map_name, licence, licence_url, checked_by)
    values ('Comet Catcher Remake 1.8', 'CC-BY-SA-3.0', 'https://example.test/comet', 'pgtap')$$,
  'a map licence is recorded against the full canonical map name'
);

-- A remade map has different terrain and may have a different author, so it is
-- a different subject rather than a newer version of this one.
select lives_ok(
  $$insert into public.asset_licence (map_name, licence, checked_by)
    values ('Comet Catcher Remake 1.9', 'CC-BY-SA-3.0', 'pgtap')$$,
  'a later revision of a map is decided separately'
);

-- A row belongs to one keyspace. The unique indexes are partial, so a row
-- filling in both collides with nothing in either and answers for both.
select throws_ok(
  $$insert into public.asset_licence (game, map_name, checked_by)
    values ('TEST', 'Comet Catcher Remake 1.8', 'pgtap')$$,
  '23514',
  null,
  'a row cannot decide for a game and for a map at once'
);

select throws_ok(
  $$insert into public.asset_licence (checked_by) values ('pgtap')$$,
  '23514',
  null,
  'a row has to be about something'
);

select throws_ok(
  $$insert into public.asset_licence (game, checked_by) values ('   ', 'pgtap')$$,
  '23514',
  null,
  'a blank shortname is refused rather than stored as a subject'
);

-- One decision per subject. Two rows for one game would let a lookup return
-- either answer, and the wrong half of that is a permanent publication.
select throws_ok(
  $$insert into public.asset_licence (game, checked_by) values ('TEST', 'pgtap')$$,
  '23505',
  null,
  'a game cannot hold two contradictory decisions'
);

select throws_ok(
  $$insert into public.asset_licence (map_name, checked_by)
    values ('Comet Catcher Remake 1.8', 'pgtap')$$,
  '23505',
  null,
  'nor can a map'
);

-- The blanket map row (issue #121). It is a third subject rather than a
-- sentinel `map_name`, because every sentinel string is a name some mapper
-- could ship, and `map_name` is documented as the canonical name and nothing
-- else. 20260814170100 already inserted the one that exists, so these assert
-- the shape holds against a second.
select throws_ok(
  $$insert into public.asset_licence (all_maps, checked_by) values (true, 'pgtap')$$,
  '23505',
  null,
  'there cannot be two defaults for maps, because a lookup would find either'
);

select throws_ok(
  $$insert into public.asset_licence (all_maps, map_name, checked_by)
    values (true, 'Comet Catcher Remake 1.9', 'pgtap')$$,
  '23514',
  null,
  'a row cannot be the map default and be about one map at the same time'
);

-- False is not a third state, it is a row saying nothing while occupying a
-- subject. The only meaning `all_maps` carries is true.
select throws_ok(
  $$insert into public.asset_licence (all_maps, checked_by) values (false, 'pgtap')$$,
  '23514',
  null,
  'all_maps is true or absent, never false'
);

-- And the point of the whole shape: a map that states its own terms is still
-- recordable, and overrides the default rather than merging with it. The two
-- Comet Catcher rows above coexist with the default inserted by a migration.
select is(
  (select redistribute_extracted from public.asset_licence
    where map_name = 'Comet Catcher Remake 1.8'),
  'unknown',
  'a map with its own row keeps its own answer, not the default'
);

select is(
  (select redistribute_extracted from public.asset_licence where all_maps), 'allowed',
  'while a map with no row of its own reads the default, which permits'
);

select has_trigger('public', 'asset_licence', 'asset_licence_touch_updated_at',
  'updated_at is maintained by the table, not trusted from whoever wrote the row');

-- RLS. #102 settled who may read and write this: the route reads it as
-- service_role and only a migration writes it, so no policy lets a browser
-- through. The grants themselves are asserted in table_privileges.test.sql
-- alongside every other table's, and the behaviour in asset_access.test.sql.
select is(
  (select relrowsecurity from pg_class where oid = 'public.asset_licence'::regclass), true,
  'row level security is on, so safety does not rest on the absence of a grant'
);

select is(
  (select count(*) from pg_policy where polrelid = 'public.asset_licence'::regclass)::int, 0,
  'and no policy lets anybody holding the publishable key through it'
);

select * from finish();
rollback;
