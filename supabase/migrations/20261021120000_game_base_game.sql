-- Record which game a mutator is built on (#546).
--
-- A mutator is a modtype 1 archive that depends on another game. The engine has
-- no mutator type, so the only sign of one is that dependency, which coilbox
-- reads from unitsync and now sends as the base game's modinfo shortname.
--
-- public.game.base_game holds that shortname as text with no foreign key, since
-- the base game may not be on the hub yet. Null means the game is not known to
-- be a mutator. public.game_browse publishes it so GET /api/v1/games can return
-- it.
--
-- submit_game_facts sets it when a submission names a base game and leaves the
-- held value alone when it does not, the rule start_units follows: a partial
-- report adds knowledge and never subtracts it. A named base game replaces the
-- held one, because the dependency is read off the archive and the newest
-- reading is the current one.
--
-- Additive. The column is nullable, the view gains a column at the end, and the
-- function is the body from 20261018210000_keep_faction_logos_on_resubmit.sql
-- with one statement added. No row is rewritten by applying this.

alter table public.game
  add column base_game text
    check (base_game is null or length(btrim(base_game)) between 1 and 64);

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
  v_links jsonb;
  v_entry jsonb;
  v_name text;
  v_digest text;
  v_build_options text[];
  v_morph_targets jsonb;
  v_held public.game_unit%rowtype;
  v_outcome text;
  v_said text;
  v_revision_id bigint;
  v_refused boolean := false;
  v_seen_factions text[] := '{}';
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

  -- The game's own name and description, and the links coilbox curated for it
  -- (#300). Each fills only the gap it finds: display_name and description
  -- when the column is still null, links when it is still the empty array
  -- every game starts with. A value already held, from an earlier report or
  -- from an owner's own edit, is never replaced.
  v_links := case
    when jsonb_typeof(p_submission -> 'links') = 'array' then p_submission -> 'links'
    else null
  end;
  update public.game as g
  set
    display_name = coalesce(g.display_name, p_submission ->> 'name'),
    description = coalesce(g.description, p_submission ->> 'description'),
    links = case
      when jsonb_array_length(g.links) = 0 and v_links is not null and jsonb_array_length(v_links) > 0
        then v_links
      else g.links
    end
  where g.id = v_game_id;

  -- The game this one is built on, when the client names one (#546). Absent
  -- leaves the held value alone, the same rule start_units follows.
  if nullif(btrim(p_submission ->> 'base_game'), '') is not null then
    update public.game as g
    set base_game = btrim(p_submission ->> 'base_game')
    where g.id = v_game_id;
  end if;

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

  -- The factions, brought in line with the submission when present.
  --
  -- A side the submission names is updated in place, so the logo on its row
  -- survives however it got there. A side a balance patch removed has to lose
  -- its row, which is what
  -- service_role holds delete on this table and nothing else in the catalog
  -- set for. Absent leaves the held set alone, the same rule start_units
  -- follows: partial submissions add knowledge, they do not subtract it.
  --
  -- Each side lands in its own subtransaction, the way a unit does: an entry
  -- the tables refuse costs itself and not the set around it, and every entry
  -- is answered by name, because a client that logs its refusals is owed one
  -- answer per side it sent.
  if jsonb_typeof(p_submission -> 'factions') = 'array' then
    delete from public.game_faction as gf
    where gf.game_id = v_game_id
      and gf.key <> all (
        array(select named.value ->> 'key' from jsonb_array_elements(p_submission -> 'factions') as named(value) where named.value ->> 'key' is not null)
      );

    for v_entry in select element.value from jsonb_array_elements(p_submission -> 'factions') as element(value)
    loop
      v_name := v_entry ->> 'key';
      v_outcome := null;
      v_said := null;

      begin
        if v_name = any (v_seen_factions) then
          -- Named twice in this submission. The plain insert fails on the
          -- identity index, which is the refusal the second one always got.
          insert into public.game_faction (game_id, key, name)
          values (v_game_id, v_entry ->> 'key', v_entry ->> 'name');
        else
          insert into public.game_faction (game_id, key, name)
          values (v_game_id, v_entry ->> 'key', v_entry ->> 'name')
          on conflict (game_id, key) do update set name = excluded.name;
        end if;
        v_seen_factions := array_append(v_seen_factions, v_name);
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
    -- What the unit turns into, stored as it arrives. Absent is coalesced to
    -- an empty array rather than left null, so on the accepted branch below
    -- it replaces whatever morph_targets was held with '[]', it does not
    -- leave the held value alone.
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
        v_refused := true;
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

  -- The mark, once every unit the complete submission named has been stored.
  -- It is never lowered: a later partial report removes nothing.
  if v_complete and not v_refused then
    update public.game_version as gv
    set reported_in_full = true
    where gv.game_id = v_game_id and gv.version = v_release;
  end if;

  return v_results;
end;
$$;

-- The same grants the creating migration wrote, restated so this file stands
-- on its own: execute belongs to the submission route's service_role and to
-- nobody else.
revoke execute on function public.submit_game_facts(jsonb, uuid) from public;
grant execute on function public.submit_game_facts(jsonb, uuid) to service_role;

-- The view is restated with base_game appended, which create or replace view
-- allows. Everything above it is the view as
-- 20261020120000_game_browse_banner.sql left it.
create or replace view public.game_browse
with (security_invoker = true) as
select
  g.shortname,
  g.display_name,
  g.description,

  -- How many sides the game has, Random left out.
  (select count(*) from public.game_faction as gf
    where gf.game_id = g.id
      and lower(btrim(gf.key)) <> 'random'
      and lower(btrim(gf.name)) <> 'random')::integer
    as faction_count,

  -- How many playable units, retired ones excluded.
  (select count(*) from public.game_unit as gu
    where gu.game_id = g.id and gu.removed_at is null)::integer
    as unit_count,

  -- How much community content is published for it (#244).
  (select count(*) from public.item as i
    where i.game_key = g.shortname and i.deleted_at is null)::integer
    as item_count,

  -- Tier relative path to the game's logo, or null when none is held (#239).
  g.logo_path,

  -- The hash of the logo's bytes, and which store holds a copy still waiting
  -- for promotion (#345).
  g.logo_hash,
  g.logo_staged_tier,

  -- Whether the listing draws this one above the divider.
  g.featured_at,

  -- Where coilbox fetches the game, best source first, as
  -- [{kind, value, asset?, filename?}] (#396). Always an array, empty when the
  -- game names nowhere, so a caller never has to tell null from none.
  (select coalesce(
      jsonb_agg(
        jsonb_strip_nulls(jsonb_build_object(
          'kind', s.kind,
          'value', s.value,
          'asset', s.asset,
          'filename', s.filename
        ))
        order by s.sort_order, s.id
      ),
      '[]'::jsonb)
    from public.game_download_source as s
    where s.game_id = g.id)
    as downloads,

  -- The card picture, resolved by the same three columns every other game
  -- picture uses.
  g.card_path,
  g.card_hash,
  g.card_staged_tier,

  -- The banner the game's page draws as its header, resolved the same way.
  g.banner_path,
  g.banner_hash,
  g.banner_staged_tier,

  -- The shortname of the game this one is built on, or null (#546).
  g.base_game
from public.game as g;

revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
