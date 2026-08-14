-- Capabilities are granted one at a time (issue #101), and the property that
-- matters is a negative one: holding the capability to seed pictures must not
-- also waive the moderation queue. Nothing in the application can prove that,
-- because the whole point is that no code anywhere joins the two, so it is
-- asserted here against the grants themselves.
--
-- The rest of this file guards the table the grants live in. public.moderator
-- has moved into it, so is_moderator() is checked here too: five policies on
-- public.item and public.report call it and none of them changed, and
-- item_rls.test.sql is what proves those still hold.

begin;
select plan(22);

create extension if not exists pgtap with schema extensions;

-- Nothing the migrations put in this table grants anything. A migration that
-- shipped a can_publish_unreviewed row would waive the moderation queue for
-- somebody on nobody's decision, which is the failure the split exists to make
-- impossible, so it is checked before this file inserts any grants of its own.
select is(
  (select count(*) from public.user_capability)::int, 0,
  'no migration grants anybody a capability'
);

-- The maintainer, somebody they trust to help seed a roster, and a moderator.
insert into auth.users (id, instance_id, aud, role, email)
values
  ('11111111-1111-1111-1111-111111111111', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'maintainer@example.test'),
  ('22222222-2222-2222-2222-222222222222', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'seeder@example.test'),
  ('33333333-3333-3333-3333-333333333333', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

select lives_ok(
  $$insert into public.user_capability (user_id, capability)
    values ('11111111-1111-1111-1111-111111111111', 'can_seed_unit_assets')$$,
  'the maintainer holds the capability to seed pictures'
);

select lives_ok(
  $$insert into public.user_capability (user_id, capability, granted_by)
    values ('22222222-2222-2222-2222-222222222222', 'can_seed_unit_assets', '11111111-1111-1111-1111-111111111111')$$,
  'and can hand it to somebody helping with a game roster'
);

-- The grant is a decision, so the record of it keeps who and when.
select is(
  (select granted_by from public.user_capability
    where user_id = '22222222-2222-2222-2222-222222222222'),
  '11111111-1111-1111-1111-111111111111'::uuid,
  'who granted it is recorded'
);

select isnt(
  (select granted_at from public.user_capability
    where user_id = '22222222-2222-2222-2222-222222222222'),
  null,
  'and when, even when the writer does not say'
);

-- The whole argument of the issue. The person above was trusted to help seed a
-- roster and nothing else, and no amount of that adds up to waiving the queue.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"22222222-2222-2222-2222-222222222222","role":"authenticated"}';

select is(
  public.has_capability('can_seed_unit_assets'), true,
  'a seeder can upload pictures that go live immediately'
);

select is(
  public.has_capability('can_publish_unreviewed'), false,
  'and still cannot publish unreviewed content, which is the point of two capabilities'
);

select is(
  public.is_moderator(), false,
  'nor moderate'
);

-- The table answers about the caller and nobody else, so a grant cannot be used
-- to enumerate who else holds one.
select throws_ok(
  $$select count(*) from public.user_capability$$,
  '42501',
  null,
  'a capability holder cannot read the table it is stored in'
);

-- A session with no account in it. The claims have to be replaced rather than
-- left behind, since `set role anon` alone would leave auth.uid() answering
-- with the seeder above and the assertion below would pass for the wrong
-- reason.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  public.has_capability('can_seed_unit_assets'), false,
  'a visitor with no session holds nothing'
);

-- Moderation. public.moderator has moved in, so a can_moderate row is what
-- is_moderator() now reads, and the five policies calling it are untouched.
reset role;

select hasnt_table('public', 'moderator',
  'the moderator table has moved into user_capability rather than sitting beside it');

insert into public.user_capability (user_id, capability)
values ('33333333-3333-3333-3333-333333333333', 'can_moderate');

set local role authenticated;
set local request.jwt.claims = '{"sub":"33333333-3333-3333-3333-333333333333","role":"authenticated"}';

select is(
  public.is_moderator(), true,
  'a can_moderate row is what makes somebody a moderator'
);

select is(
  public.has_capability('can_seed_unit_assets'), false,
  'and moderating does not carry the right to seed pictures either'
);

reset role;

-- A typo in a grant is a capability nobody holds and nothing ever checks, which
-- fails silently in the one place that must not.
select throws_ok(
  $$insert into public.user_capability (user_id, capability)
    values ('11111111-1111-1111-1111-111111111111', 'is_trusted')$$,
  '23514',
  null,
  'a capability outside the list is refused rather than stored'
);

-- Granting twice is not two grants.
select throws_ok(
  $$insert into public.user_capability (user_id, capability)
    values ('11111111-1111-1111-1111-111111111111', 'can_seed_unit_assets')$$,
  '23505',
  null,
  'the same capability cannot be granted to the same person twice'
);

-- A granter closing their account must not silently revoke what they granted,
-- and a holder closing theirs must take their capabilities with them.
delete from auth.users where id = '11111111-1111-1111-1111-111111111111';

select is(
  (select count(*) from public.user_capability
    where user_id = '22222222-2222-2222-2222-222222222222')::int,
  1,
  'a granter deleting their account does not revoke what they granted'
);

select is(
  (select granted_by from public.user_capability
    where user_id = '22222222-2222-2222-2222-222222222222'),
  null,
  'the grant survives with the granter forgotten rather than disappearing'
);

delete from auth.users where id = '22222222-2222-2222-2222-222222222222';

select is(
  (select count(*) from public.user_capability
    where user_id = '22222222-2222-2222-2222-222222222222')::int,
  0,
  'a holder deleting their account takes their capabilities with them'
);

-- RLS and grants. #102 settled it: nothing reads this table directly, not even
-- the holder of a capability and not the route, because has_capability()
-- already answers for the caller alone, and nothing writes it but the
-- maintainer as the table owner, because a role that may insert here may grant
-- itself anything. So it still refuses everyone, and that is asserted rather
-- than assumed, because #59 found a production project holding grants the
-- migrations never wrote. The three table_privs_are checks are in
-- table_privileges.test.sql alongside the others.
select is(
  (select relrowsecurity from pg_class where oid = 'public.user_capability'::regclass), true,
  'row level security is on, so safety does not rest on the absence of a grant'
);

select is(
  (select count(*) from pg_policy where polrelid = 'public.user_capability'::regclass)::int, 0,
  'and no policy lets anybody through it'
);

select function_privs_are('public', 'has_capability', ARRAY['text'], 'anon',
  ARRAY['EXECUTE'], 'anon may ask, and gets false');
select function_privs_are('public', 'has_capability', ARRAY['text'], 'authenticated',
  ARRAY['EXECUTE'], 'authenticated may ask about itself');

select * from finish();
rollback;
