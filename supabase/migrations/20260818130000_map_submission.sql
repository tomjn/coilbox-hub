-- Taking a map's facts from the machine that has the archive (issue #187).
--
-- The hub never opens a map archive. A coilbox install already has them
-- mounted, already reads their Lua, their SMF header and their infomaps, and
-- this is what it sends the result to. The route computes the slug, the digest
-- and the author keys, and everything below is what happens after that.
--
-- ## Why the whole submission is one function
--
-- Writing a map is not one statement. It is a read of the held row, a decision
-- about what the submission means, a write to public.map, a wholesale
-- replacement of public.map_point, a wholesale replacement of public.map_author
-- and sometimes a row in public.map_source_conflict. A route doing that over
-- PostgREST has no transaction: every step is its own request, and a client
-- that loses its connection halfway leaves a map holding a new row of facts
-- with the previous map's metal spots still on it. Nothing about that row looks
-- wrong afterwards.
--
-- Two clients submitting one map at the same moment is the same failure with
-- better timing. Both read that the hub holds nothing, both insert, and one of
-- them gets a unique violation on map_identity_idx after it has already written
-- points.
--
-- So the decision and the writes are one function and therefore one
-- transaction, the held row is locked for update before anything reads it, and
-- the insert that races another one is settled by map_identity_idx rather than
-- by luck. The loser of that race re-reads the winner's row and takes the
-- ordinary held path, which is the same answer it would have got a second
-- later.
--
-- The cost of the choice is that the outcome rules are in SQL rather than in
-- TypeScript, where they would be easier to unit test. That is the same trade
-- 20260818110000_author_keys.sql already made and for a stronger reason here:
-- the rules read rows, and a copy of them in the route would be a second
-- decision made from a snapshot of a row that another submission may already
-- have moved. supabase/tests/map_submission.test.sql is where they are proved.
--
-- ## Why the batch is one call
--
-- Fifty maps is fifty of those transactions and one round trip. A function
-- called once per map would be fifty round trips from a serverless function to
-- Postgres for a request the client expects one answer to.
--
-- Each entry runs inside its own block with an exception handler, which is a
-- subtransaction, so an entry that violates something is rolled back on its own
-- and reported as refused while the other forty nine stand. That is what the
-- issue means by the outcome being per map inside a 200.
--
-- ## There is no ownership rule, unlike the asset path
--
-- lib/assets/upload.ts refuses a replacement from anyone but the original
-- uploader, and that rule is right for what it protects: bytes are unreviewed
-- content, and a replacement puts an approved picture back into the queue, so
-- letting a stranger swap them takes a reviewed corpus off the site.
--
-- Facts have no such exposure. They are reproducible, so two honest clients
-- reading one archive produce identical rows and a replacement changes nothing
-- worth defending. An owner here would mean a map's facts are frozen to
-- whoever installed it first, and a later improvement to extraction could never
-- reach it. submitted_by therefore records who last submitted and grants
-- nothing.

-- ## Rate limit
--
-- The shape 20260809181909_publish_rate_limit.sql sets out: a count per account
-- per window, refused before the insert, in the database rather than in the
-- route so a second write path cannot walk around it.
--
-- Rows rather than bytes, because a catalog row is small and bytes are the
-- wrong unit for it. What is worth bounding is a client in a loop.
--
-- Five thousand an hour, which is far higher than the twenty an hour that
-- covers publishing, and the difference is the point. A first sync of a
-- complete install is a real and expected event: the catalog is roughly 3,575
-- maps, which is the figure 20260814170100_asset_licence_all_maps.sql measured,
-- and the client sends every one of them the first time it runs and almost none
-- of them afterwards, because /api/v1/maps/have tells it not to. A limit under
-- that number would break the one legitimate case that reaches it and leave the
-- abuse case a loop of the same forty nine requests. Five thousand takes a full
-- corpus in one pass with room for the catalog to grow, and stops a client that
-- has started inventing map names.
--
-- Insert only. A resubmission of a map the hub already holds writes no row: it
-- moves seen_at, or replaces facts in place, and neither grows the table. The
-- growth is what this bounds.
--
-- Seeded rows have no submitter and are not counted or limited, since a
-- migration or a maintenance script is not an account looping.
create function public.enforce_map_submission_rate_limit() returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  recent integer;
begin
  if new.submitted_by is null then
    return new;
  end if;

  select count(*) into recent
  from public.map as m
  where m.submitted_by = new.submitted_by
    and m.created_at > now() - interval '1 hour';

  if recent >= 5000 then
    raise exception 'Too many maps submitted in the last hour. Try again later.'
      using errcode = '53400';
  end if;

  return new;
end;
$$;

create trigger map_submission_rate_limit
  before insert on public.map
  for each row execute function public.enforce_map_submission_rate_limit();

-- The trigger runs on every insert and counts one account's recent rows, so the
-- count is an index read rather than a scan of the catalog. Without this a first
-- sync would read the whole table once per map it stores.
create index map_submitter_recent_idx on public.map (submitted_by, created_at);

-- One batch of submitted maps, decided and written.
--
-- Takes the maps as jsonb rather than as a set of arguments because an entry is
-- twenty one fields and a few hundred points, and a function signature holding
-- that is a signature every change to the catalog has to alter on both sides.
-- The route has already parsed and normalised what arrives here: every optional
-- field is present as null, the points are in the order they will be stored in,
-- and the numbers are numbers. So this reads known keys and does not validate
-- the shape a second time.
--
-- Each element is one submission: the entry itself, the two slug candidates and
-- the digest the hub computed over the entry. Those three are separate from the
-- entry rather than merged into it because the digest is over the facts alone.
-- Folding the hub's own derivations into the thing being hashed would make the
-- digest depend on the slug, and a slug that changed would then read as changed
-- facts.
--
-- Returns an array of `{ map_name, outcome, said }`, one per element and in the
-- order they were given, so the caller zips by index.
--
-- Returned as one jsonb value rather than as a table of three columns, and that
-- is not a preference. An out parameter named map_name is a name every query in
-- the function can also read as a column of public.map, and Postgres refuses the
-- ambiguity at runtime rather than at creation. Renaming the outputs to avoid it
-- would put the awkward name on the wire, where a client has to live with it. A
-- jsonb result has no out parameters at all, so every name inside the function
-- is a local and nothing can collide.
create function public.submit_map_facts(p_maps jsonb, p_submitted_by uuid)
returns jsonb
language plpgsql
set search_path = ''
as $$
declare
  results jsonb := '[]'::jsonb;
  submission jsonb;
  v_entry jsonb;
  v_name text;
  v_slug text;
  v_digest text;
  v_version integer;
  v_hash text;
  v_id uuid;
  v_outcome text;
  v_said text;
  held public.map%rowtype;
begin
  for submission in select element.value from jsonb_array_elements(p_maps) as element(value)
  loop
    v_entry := submission -> 'entry';
    v_name := v_entry ->> 'map_name';
    v_slug := submission ->> 'slug';
    v_digest := submission ->> 'facts_digest';
    v_hash := v_entry ->> 'source_hash';
    v_version := (v_entry ->> 'catalog_version')::integer;

    v_outcome := null;
    v_said := null;
    v_id := null;

    -- One entry, one subtransaction. Anything this entry does that the tables
    -- refuse is rolled back to here and reported against this map alone, so a
    -- single bad entry in a batch of fifty costs one map rather than all of
    -- them.
    begin
      -- Locked before it is read, so two submissions of one map queue up rather
      -- than both deciding against the same snapshot and both writing.
      select m.* into held
      from public.map as m
      where m.map_name = v_name
      for update;

      if held.id is null then
        -- Two different canonical names can render to one slug, and the unique
        -- index would then refuse the second map outright. The alternative slug
        -- carries a suffix taken from the map's own name, so the second map
        -- gets a URL of its own rather than losing its facts to a collision.
        --
        -- Checked rather than caught, because catching would mean writing the
        -- insert out twice. Two maps racing for one free slug is left to the
        -- unique index: the loser is refused, and its next submission finds the
        -- slug taken and takes the alternative.
        if exists (select 1 from public.map as m where m.slug = v_slug) then
          v_slug := submission ->> 'slug_alternative';
        end if;

        insert into public.map (
          map_name, slug, display_name, description, map_version, archive_filename,
          width_elmos, height_elmos, world_height_min, world_height_max,
          min_wind, max_wind, tidal_strength, void_water, void_ground, water_coverage,
          appearance, source_hash, source_archive, catalog_version, facts_digest,
          submitted_by
        )
        values (
          v_name,
          v_slug,
          v_entry ->> 'display_name',
          v_entry ->> 'description',
          v_entry ->> 'map_version',
          v_entry ->> 'archive_filename',
          (v_entry ->> 'width_elmos')::integer,
          (v_entry ->> 'height_elmos')::integer,
          (v_entry ->> 'world_height_min')::real,
          (v_entry ->> 'world_height_max')::real,
          (v_entry ->> 'min_wind')::real,
          (v_entry ->> 'max_wind')::real,
          (v_entry ->> 'tidal_strength')::real,
          (v_entry ->> 'void_water')::boolean,
          (v_entry ->> 'void_ground')::boolean,
          (v_entry ->> 'water_coverage')::real,
          coalesce(nullif(v_entry -> 'appearance', 'null'::jsonb), '{}'::jsonb),
          v_hash,
          v_entry ->> 'source_archive',
          v_version,
          v_digest,
          p_submitted_by
        )
        on conflict (map_name) do nothing
        returning id into v_id;

        if v_id is null then
          -- Another submission inserted this map between the read and the
          -- write. The insert waited for it to commit before deciding there was
          -- a conflict, so its row is readable now, and this entry carries on as
          -- though it had been there all along.
          select m.* into held
          from public.map as m
          where m.map_name = v_name
          for update;
        else
          v_outcome := 'stored';
        end if;
      end if;

      if v_outcome is null then
        v_id := held.id;

        if held.source_hash is distinct from v_hash then
          -- A different hash under one canonical name. That is not a version
          -- rollover: a mapper who changes a map releases it with a new version
          -- in its name, so one name is one archive permanently, and two sets of
          -- bytes under one name is a modified or corrupt install. Those two
          -- players are already out of sync with each other in a lobby, so
          -- refusing the second submission and writing the disagreement down is
          -- the right handling twice over.
          --
          -- Nothing else is held back. The row does not move, seen_at does not
          -- move, and the other maps in the batch are unaffected.
          --
          -- One row per distinct reported hash, which map_source_conflict_report_idx
          -- gives, so a client looping on a refused submission leaves one record
          -- rather than a table full of them.
          insert into public.map_source_conflict (
            map_id, source_archive, held_source_hash, reported_source_hash, reported_by
          )
          values (
            held.id,
            v_entry ->> 'source_archive',
            held.source_hash,
            v_hash,
            p_submitted_by
          )
          on conflict (map_id, reported_source_hash) do nothing;

          v_outcome := 'conflict';

        elsif v_version > held.catalog_version then
          -- The same archive read by a newer extraction, which is the whole
          -- reason catalog_version is on the row. The new read is better by
          -- definition, so it takes the row.
          --
          -- The slug is not rewritten. It is derived from map_name, which cannot
          -- have changed under one identity, and a slug that moved would break
          -- every link to the map that already exists.
          update public.map as m
          set
            display_name = v_entry ->> 'display_name',
            description = v_entry ->> 'description',
            map_version = v_entry ->> 'map_version',
            archive_filename = v_entry ->> 'archive_filename',
            width_elmos = (v_entry ->> 'width_elmos')::integer,
            height_elmos = (v_entry ->> 'height_elmos')::integer,
            world_height_min = (v_entry ->> 'world_height_min')::real,
            world_height_max = (v_entry ->> 'world_height_max')::real,
            min_wind = (v_entry ->> 'min_wind')::real,
            max_wind = (v_entry ->> 'max_wind')::real,
            tidal_strength = (v_entry ->> 'tidal_strength')::real,
            void_water = (v_entry ->> 'void_water')::boolean,
            void_ground = (v_entry ->> 'void_ground')::boolean,
            water_coverage = (v_entry ->> 'water_coverage')::real,
            appearance = coalesce(nullif(v_entry -> 'appearance', 'null'::jsonb), '{}'::jsonb),
            source_archive = v_entry ->> 'source_archive',
            catalog_version = v_version,
            facts_digest = v_digest,
            submitted_by = p_submitted_by,
            seen_at = now()
          where m.id = held.id;

          v_outcome := 'replaced';

        elsif v_version < held.catalog_version then
          -- An older extraction of the same archive. The stored row came from a
          -- better read of the same bytes, so taking this one would talk the
          -- catalog backwards, which is the one failure the version comparison
          -- exists to prevent. The client is being honest and enthusiastic and
          -- the hub declines politely.
          --
          -- seen_at still moves, and that is not the row moving. It records when
          -- a client last reported the map present, which this client has just
          -- done. Nothing about the facts changes.
          update public.map as m set seen_at = now() where m.id = held.id;
          v_outcome := 'unchanged';

        elsif held.facts_digest = v_digest then
          -- The same archive, the same extraction, the same facts. This is the
          -- ordinary case and the reason the digest exists: one string equality
          -- rather than a comparison of twenty columns and a few hundred points,
          -- which would read as unchanged the first time somebody added a column
          -- and forgot the list.
          update public.map as m set seen_at = now() where m.id = held.id;
          v_outcome := 'unchanged';

        else
          -- The same bytes, the same extraction, different facts. Extraction is
          -- deterministic, so this cannot happen between two honest clients
          -- running the same code, and one of the two readings is wrong.
          --
          -- Nothing is written, and no conflict row either. map_source_conflict
          -- records a disagreement about an archive's bytes and its check
          -- constraint refuses a row whose two hashes agree, which these do.
          -- The disagreement here is about the reading rather than the archive,
          -- and the record that matters for it is the pair of catalog versions,
          -- which are equal and already on the row.
          v_outcome := 'conflict';
        end if;
      end if;

      -- The points and the credits, written the same way whether the map is new
      -- or replaced, because a replacement is not an edit. An archive re-read at
      -- a newer catalog version can drop a metal spot the old extractor invented
      -- or a co-author it misparsed, and a merge could only ever add. So the old
      -- set goes and the new set is written, which is what service_role holds
      -- delete on these two tables and on nothing else for.
      if v_outcome in ('stored', 'replaced') then
        delete from public.map_point as p where p.map_id = v_id;

        insert into public.map_point (map_id, kind, ordinal, x, z, y, meta)
        select
          v_id,
          kind.key,
          (point.ordinality - 1)::integer,
          (point.value ->> 'x')::real,
          (point.value ->> 'z')::real,
          (point.value ->> 'y')::real,
          nullif(point.value -> 'meta', 'null'::jsonb)
        from jsonb_each(v_entry -> 'points') as kind
        cross join lateral jsonb_array_elements(kind.value)
          with ordinality as point(value, ordinality);

        delete from public.map_author as a where a.map_id = v_id;

        -- The credits, split and keyed by the database rather than by the route.
        -- 20260818110000_author_keys.sql argues that at length: every read path
        -- has to arrive at the same key from a name somebody typed or clicked,
        -- so the rules are needed on both sides, and two copies of a normaliser
        -- in two languages drift silently.
        --
        -- A credit that keys to nothing is dropped. It is a clan tag with no
        -- name behind it, and map_author.key refuses a blank anyway, so storing
        -- it would file every clan signed map under one mapper who does not
        -- exist.
        --
        -- credit_index is renumbered over what is left rather than carrying the
        -- gap a dropped credit leaves. The column is the order the archive
        -- credited them in, and once a credit is dropped no numbering matches
        -- the archive exactly, so the one that reads correctly wins.
        insert into public.map_author (map_id, credit_index, raw, key)
        select
          v_id,
          (row_number() over (order by keyed.ordinality) - 1)::integer,
          keyed.raw,
          keyed.key
        from (
          select
            credit.value as raw,
            credit.ordinality,
            public.resolved_author_key(public.author_key(credit.value)) as key
          from unnest(public.author_credits(v_entry ->> 'author'))
            with ordinality as credit(value, ordinality)
        ) as keyed
        where btrim(keyed.key) <> '';
      end if;

    exception
      -- The rate limit is not this map's problem, it is the account's, and the
      -- answer to it is a 429 for the request rather than fifty refusals. So it
      -- goes back up, the whole batch rolls back, and the client retries the
      -- same batch later against a hub that has written nothing.
      when sqlstate '53400' then
        raise;

      -- Everything else is this entry alone: a value the columns cannot hold, a
      -- credit longer than the column, a slug another map won the race for. The
      -- message is passed through rather than replaced, because the caller is a
      -- client author debugging an extractor and the constraint that refused the
      -- row is the useful part. Nothing here is a secret: the catalog grants
      -- select on every one of these tables to anon.
      when others then
        v_outcome := 'refused';
        v_said := sqlerrm;
    end;

    -- Appended rather than collected at the end, so the answers come back in the
    -- order the batch was given whatever each entry did.
    results := results || jsonb_build_object(
      'map_name', v_name,
      'outcome', v_outcome,
      'said', v_said
    );
  end loop;

  return results;
end;
$$;

-- ## Access
--
-- Execute is granted to PUBLIC on every new function, so the revokes are the
-- access control rather than a tidy-up, which is the discipline every migration
-- has followed since #59 found production holding grants these migrations never
-- wrote.
revoke execute on function public.submit_map_facts(jsonb, uuid) from public;
revoke execute on function public.enforce_map_submission_rate_limit() from public;

-- The submission route and nothing else. It runs as service_role, which already
-- holds the writes this makes and holds execute on the three author functions it
-- calls, so the function is security invoker and adds no privilege to anything.
-- A browser holding the publishable key can no more call this than it can write
-- the rows behind it.
grant execute on function public.submit_map_facts(jsonb, uuid) to service_role;
