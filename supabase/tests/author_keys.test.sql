-- The three functions that turn a mapinfo credit into an author (issue #183),
-- asserted one rule at a time.
--
-- Every failure they can have is quiet. A split that misses `and` files two
-- people under one name, a tag that survives folding gives one person two
-- author pages, and a stripped inner bracket keys two different mappers the
-- same. None of them raises anything. What they produce is a page that lists
-- some of somebody's maps, or somebody else's, and looks entirely plausible
-- either way. So each rule gets an assertion, and the cases that must not
-- change - `Sandra`, an inner bracket, a handle that is not ASCII - get one too.
--
-- Grants are not tested here. The functions read nothing anon cannot already
-- select, and map_access.test.sql and table_privileges.test.sql own the access
-- rules for the catalog they read.

begin;
select plan(33);

create extension if not exists pgtap with schema extensions;

-- ## Splitting a credit

select is(
  public.author_credits('Beherith & Icexuick'), array['Beherith', 'Icexuick'],
  'two people in one credit string are two credits'
);

select is(
  public.author_credits('Smith and Sons'), array['Smith', 'Sons'],
  'and the word and separates them as well as the symbol'
);

select is(
  public.author_credits('Beherith'), array['Beherith'],
  'one author is a one element array, so every caller takes the same path'
);

select is(
  public.author_credits('Beherith + Icexuick'), array['Beherith', 'Icexuick'],
  'a plus separates them too'
);

-- Array equality is ordered, so this asserts the credits come back in the order
-- the archive listed them, which is what public.map_author.credit_index stores.
select is(
  public.author_credits('Beherith, Icexuick, Jools'),
  array['Beherith', 'Icexuick', 'Jools'],
  'a comma separated list keeps the order the archive gave'
);

select is(
  public.author_credits('Beherith AND Icexuick'), array['Beherith', 'Icexuick'],
  'the separator is matched whatever case it is written in'
);

-- The whole reason `and` is matched as a word. Splitting inside a name invents
-- two mappers, neither of whom exists.
select is(
  public.author_credits('Sandra Andrew'), array['Sandra Andrew'],
  'a name that merely contains the letters a n d is one credit'
);

select is(
  public.author_credits('Beherith & , & Icexuick'), array['Beherith', 'Icexuick'],
  'a separator with nothing between it and the next one credits nobody'
);

select is(
  public.author_credits('Beherith &'), array['Beherith'],
  'and a trailing separator leaves one credit rather than a blank second one'
);

-- A credit string with nothing in it names nobody, so a caller has an empty
-- list to loop over rather than one credit for a person with no name.
select is(
  public.author_credits(''), '{}'::text[],
  'an empty credit string names nobody'
);

select is(
  public.author_credits(null::text), '{}'::text[],
  'and neither does a missing one'
);

-- ## Keying a credit

select is(
  public.author_key('[BAR]Beherith'), 'beherith',
  'a clan tag on the front is not part of the name'
);

select is(
  public.author_key('Beherith [BAR]'), 'beherith',
  'nor is one on the back'
);

select is(
  public.author_key('  BEHERITH '), 'beherith',
  'and case and surrounding space are not either'
);

select is(
  public.author_key('(BAR) Beherith'), 'beherith',
  'a tag in parentheses is a tag'
);

select is(
  public.author_key('{BAR}Beherith'), 'beherith',
  'and so is one in braces'
);

select is(
  public.author_key('[BAR] Beherith [XYZ]'), 'beherith',
  'a credit tagged at both ends still keys to the person in the middle'
);

-- The case that stops the tag rule being a plain "delete every bracket". An
-- inner bracket is part of the handle, and folding it away would key two
-- different mappers the same.
select is(
  public.author_key('Behe[r]ith'), 'behe[r]ith',
  'a bracket inside a handle is part of the handle'
);

select is(
  public.author_key('Bob_the-Builder'), 'bob_the-builder',
  'and so is punctuation inside one'
);

select is(
  public.author_key('-Beherith!'), 'beherith',
  'punctuation at either end is not part of the name'
);

select is(
  public.author_key('Comet   Catcher'), 'comet catcher',
  'and a name spaced out twice is one key rather than two'
);

-- Mappers are worldwide. The trimming names punctuation rather than "not a
-- letter" so that a handle Postgres may not call letters survives it.
select is(
  public.author_key('Zoë'), 'zoë',
  'a handle that is not ASCII keeps every letter it has'
);

-- A group with no name behind it is not a person. Keying it as `bar` would file
-- every clan signed map in the catalog under one mapper who does not exist, and
-- public.map_author.key refuses a blank, so ingest drops the credit instead.
select is(
  public.author_key('[BAR]'), '',
  'a credit that is nothing but a tag keys to nothing'
);

select is(
  public.author_key(''), '',
  'an empty credit keys to nothing'
);

select is(
  public.author_key(null::text), '',
  'and so does a missing one'
);

-- ## Resolving a merge

-- Two spellings a maintainer has said are one person, and a second row pointing
-- at the first, which is the chain this must not follow.
insert into public.author_alias (from_key, to_key, note)
values
  ('bherith', 'beherith', 'Typo in one archive'),
  ('bh', 'bherith', 'A merge somebody aimed at the alias rather than the person');

select is(
  public.resolved_author_key('bherith'), 'beherith',
  'a key a maintainer has merged resolves to the person they merged it into'
);

select is(
  public.resolved_author_key('beherith'), 'beherith',
  'and a key with no alias row comes back exactly as it went in'
);

-- One hop, and the reader stops there. Following the chain would land on
-- beherith, which is where the maintainer probably meant to point bh, but
-- probably is not a thing a lookup gets to decide.
select is(
  public.resolved_author_key('bh'), 'bherith',
  'a merge into another alias resolves one hop and no further'
);

select is(
  public.resolved_author_key(null::text), null,
  'a missing key has no alias and stays missing'
);

-- An alias to itself is the obvious way to write a loop, and the table refuses
-- it outright.
select throws_ok(
  $$insert into public.author_alias (from_key, to_key) values ('jools', 'jools')$$,
  '23514',
  null,
  'an alias pointing at itself is refused'
);

-- The loop the table cannot refuse: two rows pointing at each other. The single
-- lookup is what makes it terminate anyway, in both directions.
insert into public.author_alias (from_key, to_key)
values ('ying', 'yang'), ('yang', 'ying');

select is(
  public.resolved_author_key('ying'), 'yang',
  'a cycle written across two rows resolves one hop and stops'
);

select is(
  public.resolved_author_key('yang'), 'ying',
  'and stops the same way read from the other end'
);

-- ## The three of them together

-- What ingest actually does with a mapinfo string: split it, key each credit,
-- and count it under whatever a maintainer has merged it into.
select is(
  (
    select array_agg(public.resolved_author_key(public.author_key(credit)) order by ordinality)
    from unnest(public.author_credits('[BAR]Bherith & Icexuick'))
      with ordinality as split(credit, ordinality)
  ),
  array['beherith', 'icexuick'],
  'a tagged, merged, two person credit string becomes the two authors it names'
);

select * from finish();
rollback;
