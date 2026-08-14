-- public.asset_licence decides whether anything may be published at all
-- (issue #97), and the durable tier is a public git repository whose history is
-- permanent. So the failure this file guards against is not a broken page, it
-- is an unpublishable thing published, and the only cheap moment to catch it is
-- before the insert.
--
-- Two properties matter more than the rest and are asserted first: a row grants
-- nothing by existing, and saying yes has to say what the yes rests on.

begin;
select plan(22);

create extension if not exists pgtap with schema extensions;

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

select has_trigger('public', 'asset_licence', 'asset_licence_touch_updated_at',
  'updated_at is maintained by the table, not trusted from whoever wrote the row');

-- Grants and RLS. #102 settles who may read and write this alongside the asset
-- table, and until it does the table refuses everyone. That is asserted rather
-- than assumed, because #59 found a production project holding grants these
-- migrations never wrote.
select is(
  (select relrowsecurity from pg_class where oid = 'public.asset_licence'::regclass), true,
  'row level security is on, so safety does not rest on the absence of a grant'
);

select table_privs_are('public', 'asset_licence', 'anon', ARRAY[]::name[],
  'anon holds no table privilege on asset_licence');
select table_privs_are('public', 'asset_licence', 'authenticated', ARRAY[]::name[],
  'authenticated holds no table privilege on asset_licence');
select table_privs_are('public', 'asset_licence', 'service_role', ARRAY[]::name[],
  'service_role holds no table privilege on asset_licence');

select * from finish();
rollback;
