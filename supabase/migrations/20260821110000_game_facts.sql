-- Taking a game's facts from the machine that has the archive (issue #224).
--
-- The hub never opens a game archive. A coilbox install already has them
-- mounted, already reads their Lua through its unitsync worker, and this is
-- what it sends the result to. The route computes the digest over each unit's
-- normalised entry; everything below is what happens after that.
--
-- ## Why the whole submission is one function
--
-- Writing one request is not one statement. It is a read of the game row, an
-- insert when nobody has heard of the shortname before, an upsert of the
-- release, sometimes a wholesale replacement of the faction set, an upsert per
-- unit, a revision row per unit and, when the submission declared itself
-- complete, one retirement pass over every unit it did not name. A route doing
-- that over PostgREST has no transaction: every step is its own request, and a
-- client that loses its connection halfway leaves a game holding new units
-- beside the previous backfill's factions. Nothing about that state looks wrong
-- afterwards, which is exactly what makes it dangerous.
--
-- So the decision and the writes are one function and therefore one
-- transaction, the game row is locked for update before anything reads it, and
-- two clients submitting one game queue up rather than interleaving.
--
-- The cost is that the outcome rules are in SQL rather than in TypeScript,
-- where they would be easier to unit test. That is the same trade
-- 20260818130000_map_submission.sql made, for a stronger reason here: the
-- retirement pass reads the whole batch, so a copy of it outside the
-- transaction would be deciding from a snapshot another submission may already
-- have moved. supabase/tests/game_submission.test.sql is where the rules are
-- proved.
--
-- ## There is no rate limit trigger, unlike the map path
--
-- public.map mints a row per map per account, so a runaway client mints rows,
-- and the hourly count on inserts is what bounds it. A game's identity refuses
-- a second shortname outright, units upsert against identity rather than
-- minting, and the parser's caps bound a request before the database sees it.
-- The one insert a repeated submission can churn - game_version - collides into
-- a last_seen_at touch. Nothing here needs counting.

-- One submitted game, decided and written.
--
-- Takes the submission as jsonb rather than as a set of arguments because it
-- carries three lists, and a signature holding them is a signature every change
-- to the catalog has to alter on both sides. The route has already parsed and
-- normalised everything: optional text is present as null or absent, build
-- options are sorted and deduplicated, and every unit carries the digest the
-- hub computed over it. So this reads known keys and does not validate the
-- shape a second time.
--
-- Returns an array of `{ kind, name, outcome, said }`, one per faction and
-- unit and in submission order, so the caller zips by index.
--
-- Outcomes, and why there are four rather than the three a map has:
--
-- accepted  current facts changed, and this release's revision was written.
-- recorded  current facts were already held, but this release had no revision
--           yet. The revision is new even though nothing else moved, which is
--           the ordinary case the second time a release is reported, and
--           folding it into unchanged would claim nothing was written when a
--           row was.
-- unchanged nothing was written at all.
-- refused   the entry was something the tables would not hold, and said
--           carries why.
create function public.submit_game_facts(p_submission jsonb, p_submitted_by uuid)
returns jsonb
language plpgsql
set search_path = ''
as $$
declare
  v_results jsonb := '[]'::jsonb;
  v_game public.game%rowtype;
  v_game_id uuid;
  v_release text;
  v_complete boolean;
  v_start_units text[];
  v_entry jsonb;
  v_name text;
  v_digest text;
  v_build_options text[];
  v_held public.game_unit%rowtype;
  v_outcome text;
  v_said text;
  v_revision_id bigint;
begin
  -- Locked before it is read, so two submissions of one game queue up rather
  -- than both deciding against the same snapshot and both writing. Everything
  -- below keys off this row, which is also what keeps the faction replacement
  -- and the retirement pass inside one view of the catalog.
  select g.* into v_game
  from public.game as g
  where g.shortname = p_submission ->> 'shortname'
  for update;

  if v_game.id is null then
    -- The first report of a shortname creates the game, exactly as a map's
    -- facts can arrive before its pictures.
    --
    -- The roots arrive as a JSON array and leave as text[], which Postgres will
    -- not do implicitly, so they are walked once here rather than cast at every
    -- use. An empty array is kept as empty: reported none and never reported
    -- are different facts, and the column's null exists to tell them apart.
    v_start_units := case
      when jsonb_typeof(p_submission -> 'startUnits') = 'array'
        then array(select jsonb_array_elements_text(p_submission -> 'startUnits'))
      else null
    end;

    insert into public.game (shortname, display_name, description, links, start_units, submitted_by)
    values (p_submission ->> 'shortname', null, null, '[]'::jsonb, v_start_units, p_submitted_by)
    on conflict (shortname) do nothing
    returning * into v_game;

    if v_game.id is null then
      -- Another submission inserted this game between the read and the write.
      -- Its row is committed now, so this entry carries on as though it had
      -- been there all along.
      select g.* into v_game
      from public.game as g
      where g.shortname = p_submission ->> 'shortname'
      for update;
    end if;
  end if;

  v_game_id := v_game.id;
  v_release := p_submission ->> 'release';
  v_complete := coalesce((p_submission ->> 'complete')::boolean, false);

  -- The roots a build tree groups by, when the client knows them. Absent
  -- leaves the held value alone, because a stats-only partial backfill must not
  -- overwrite a fuller earlier one.
  if jsonb_typeof(p_submission -> 'startUnits') = 'array' then
    v_start_units := array(select jsonb_array_elements_text(p_submission -> 'startUnits'));
    update public.game as g set start_units = v_start_units where g.id = v_game_id;
  end if;

  -- The release, upserted so the picker's list grows without walking revisions
  -- to derive it. last_seen_at moves on every report; first_seen_at stays.
  insert into public.game_version as gv (game_id, version)
  values (v_game_id, v_release)
  on conflict (game_id, version) do update set last_seen_at = now();

  -- The factions, replaced wholesale when present.
  --
  -- A side a balance patch removed has to lose its row, which is what
  -- service_role holds delete on this table and nothing else in the catalog
  -- set for. Absent leaves the held set alone, the same rule start_units
  -- follows: partial submissions add knowledge, they do not subtract it.
  if jsonb_typeof(p_submission -> 'factions') = 'array' then
    delete from public.game_faction as gf where gf.game_id = v_game_id;

    insert into public.game_faction (game_id, key, name)
    select v_game_id, faction.value ->> 'key', faction.value ->> 'name'
    from jsonb_array_elements(p_submission -> 'factions') as faction(value);
  end if;

  -- One unit at a time, each in its own subtransaction, so a unit the tables
  -- refuse costs itself and not the nine hundred entries around it.
  for v_entry in select element.value from jsonb_array_elements(p_submission -> 'units') as element(value)
  loop
    -- The envelope is the unit beside its digest, because the digest is over
    -- the facts alone: folding the hub's derivation into the thing being
    -- hashed would make it depend on itself.
    v_name := v_entry -> 'unit' ->> 'name';
    v_digest := v_entry ->> 'facts_digest';
    -- Build options arrive as a JSON array and leave as text[], walked once
    -- per unit for the same reason start_units was.
    v_build_options := array(
      select jsonb_array_elements_text(v_entry -> 'unit' -> 'build_options')
    );
    v_outcome := null;
    v_said := null;
    v_revision_id := null;

    begin
      select u.* into v_held
      from public.game_unit as u
      where u.game_id = v_game_id and u.unit_name = v_name
      for update;

      if v_held.id is null then
        insert into public.game_unit (
          game_id, unit_name, full_name, faction_key, build_options, stats,
          facts_digest, source_version
        )
        values (
          v_game_id,
          v_name,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          coalesce(nullif(v_entry -> 'unit' -> 'stats', 'null'::jsonb), '{}'::jsonb),
          v_digest,
          v_release
        )
        returning * into v_held;

        v_outcome := 'accepted';
      elsif v_held.facts_digest <> v_digest then
        -- Different facts under one identity: a balance patch moved something,
        -- or a newer extraction read better. Either way the newest reading is
        -- the current one, and source_version names the release it came from.
        update public.game_unit as u
        set
          full_name = v_entry -> 'unit' ->> 'full_name',
          faction_key = v_entry -> 'unit' ->> 'faction_key',
          build_options = v_build_options,
          stats = coalesce(nullif(v_entry -> 'unit' -> 'stats', 'null'::jsonb), '{}'::jsonb),
          facts_digest = v_digest,
          source_version = v_release,
          removed_at = null,
          last_seen_at = now()
        where u.id = v_held.id;

        v_outcome := 'accepted';
      elsif v_complete then
        -- Same facts, still listed. Presence in a complete submission is the
        -- answer to removed_at, wherever it currently points: a unit a patch
        -- retired and a later one brought back comes back here.
        update public.game_unit as u
        set removed_at = null, last_seen_at = now()
        where u.id = v_held.id;
      else
        update public.game_unit as u set last_seen_at = now() where u.id = v_held.id;
      end if;

      -- The revision, written whatever the current-row outcome was. History
      -- keyed by version means "this release" is one lookup, and a re-extraction
      -- that got a release wrong replaces its row rather than appending a
      -- phantom second opinion.
      if v_outcome = 'accepted' then
        insert into public.game_unit_revision (unit_id, version, full_name, faction_key, build_options, stats, facts_digest)
        values (
          v_held.id, v_release,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          coalesce(nullif(v_entry -> 'unit' -> 'stats', 'null'::jsonb), '{}'::jsonb),
          v_digest
        )
        on conflict (unit_id, version) do update set
          full_name = excluded.full_name,
          faction_key = excluded.faction_key,
          build_options = excluded.build_options,
          stats = excluded.stats,
          facts_digest = excluded.facts_digest;
      else
        insert into public.game_unit_revision (unit_id, version, full_name, faction_key, build_options, stats, facts_digest)
        values (
          v_held.id, v_release,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          coalesce(nullif(v_entry -> 'unit' -> 'stats', 'null'::jsonb), '{}'::jsonb),
          v_digest
        )
        on conflict (unit_id, version) do nothing
        returning id into v_revision_id;

        if v_revision_id is not null then
          v_outcome := 'recorded';
        elsif v_outcome is null then
          v_outcome := 'unchanged';
        end if;
      end if;

    exception
      when others then
        v_outcome := 'refused';
        v_said := sqlerrm;
    end;

    -- Appended rather than collected at the end, so the answers come back in
    -- the order the batch was given whatever each entry did.
    v_results := v_results || jsonb_build_object(
      'kind', 'unit',
      'name', v_name,
      'outcome', v_outcome,
      'said', v_said
    );
  end loop;

  -- Retirement, only on a complete declaration. Every unit the game holds that
  -- this submission did not name is marked retired rather than deleted, because
  -- an old replay still names it. A partial submission removes nothing, which
  -- is the whole difference complete makes.
  if v_complete then
    update public.game_unit as u
    set removed_at = now()
    where u.game_id = v_game_id
      and u.removed_at is null
      and not exists (
        select 1
        from jsonb_array_elements(p_submission -> 'units') as named(value)
        where named.value -> 'unit' ->> 'name' = u.unit_name
      );
  end if;

  return v_results;
end;
$$;

-- ## Access
--
-- Execute is granted to PUBLIC on every new function, so the revokes are the
-- access control rather than a tidy-up, which is the discipline every migration
-- has followed since #59 found production holding grants these migrations never
-- wrote.
revoke execute on function public.submit_game_facts(jsonb, uuid) from public;

-- The submission route and nothing else. It runs as service_role, which already
-- holds the writes this makes, so the function is security invoker and adds no
-- privilege to anything. A browser holding the publishable key can no more call
-- this than it can write the rows behind it.
grant execute on function public.submit_game_facts(jsonb, uuid) to service_role;
