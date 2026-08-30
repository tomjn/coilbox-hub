-- What a unit turns into (coilbox#2063).
--
-- A commander that upgrades through tech levels is one unit at five stages of
-- its life, and the catalog held five unrelated rows. This is the second
-- relationship between units the table stores, beside build_options.
--
-- jsonb rather than text[], because the conditions belong to the edge: a level
-- to reach, a cost to pay, a time to wait, spelled differently by every game
-- that has them. Splitting the target from what it costs would need a second
-- key joining two columns nothing keeps in step.
--
-- A new migration carrying the whole function rather than an edit of the
-- applied one, per the house rule. Stacked on
-- 20260822130000_faction_answers.sql, the true current body; this is that
-- function plus morph_targets, unchanged elsewhere.

alter table public.game_unit
  add column if not exists morph_targets jsonb not null default '[]'::jsonb;

alter table public.game_unit_revision
  add column if not exists morph_targets jsonb not null default '[]'::jsonb;

create or replace function public.submit_game_facts(p_submission jsonb, p_submitted_by uuid)
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
  v_morph_targets jsonb;
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
      when jsonb_typeof(p_submission -> 'start_units') = 'array'
        then array(select jsonb_array_elements_text(p_submission -> 'start_units'))
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
  if jsonb_typeof(p_submission -> 'start_units') = 'array' then
    v_start_units := array(select jsonb_array_elements_text(p_submission -> 'start_units'));
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
  --
  -- Each side lands in its own subtransaction, the way a unit does: an entry
  -- the tables refuse costs itself and not the set around it, and every entry
  -- is answered by name, because a client that logs its refusals is owed one
  -- answer per side it sent.
  if jsonb_typeof(p_submission -> 'factions') = 'array' then
    delete from public.game_faction as gf where gf.game_id = v_game_id;

    for v_entry in select element.value from jsonb_array_elements(p_submission -> 'factions') as element(value)
    loop
      v_name := v_entry ->> 'key';
      v_outcome := null;
      v_said := null;

      begin
        insert into public.game_faction (game_id, key, name)
        values (v_game_id, v_entry ->> 'key', v_entry ->> 'name');
        v_outcome := 'accepted';
      exception
        when others then
          v_outcome := 'refused';
          v_said := sqlerrm;
      end;

      -- Factions are appended before the units are walked, which is the order
      -- the route zips against.
      v_results := v_results || jsonb_build_object(
        'kind', 'faction',
        'name', v_name,
        'outcome', v_outcome,
        'said', v_said
      );
    end loop;
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
    -- What the unit turns into, stored as it arrives: absent means an older
    -- client that never learned to send it, so it stores nothing rather than
    -- null.
    v_morph_targets := coalesce(v_entry -> 'unit' -> 'morph_targets', '[]'::jsonb);
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
          game_id, unit_name, full_name, faction_key, build_options, morph_targets, stats,
          facts_digest, source_version
        )
        values (
          v_game_id,
          v_name,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          v_morph_targets,
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
          morph_targets = v_morph_targets,
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
        insert into public.game_unit_revision (unit_id, version, full_name, faction_key, build_options, morph_targets, stats, facts_digest)
        values (
          v_held.id, v_release,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          v_morph_targets,
          coalesce(nullif(v_entry -> 'unit' -> 'stats', 'null'::jsonb), '{}'::jsonb),
          v_digest
        )
        on conflict (unit_id, version) do update set
          full_name = excluded.full_name,
          faction_key = excluded.faction_key,
          build_options = excluded.build_options,
          morph_targets = excluded.morph_targets,
          stats = excluded.stats,
          facts_digest = excluded.facts_digest;
      else
        insert into public.game_unit_revision (unit_id, version, full_name, faction_key, build_options, morph_targets, stats, facts_digest)
        values (
          v_held.id, v_release,
          v_entry -> 'unit' ->> 'full_name',
          v_entry -> 'unit' ->> 'faction_key',
          v_build_options,
          v_morph_targets,
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

-- The same grants the creating migration wrote, restated so this file stands
-- on its own: execute belongs to the submission route's service_role and to
-- nobody else.
revoke execute on function public.submit_game_facts(jsonb, uuid) from public;
grant execute on function public.submit_game_facts(jsonb, uuid) to service_role;
