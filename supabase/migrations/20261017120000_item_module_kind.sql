-- Unit builder modules, the seventh kind the gallery carries (issue #507). A
-- module is a named tree of pieces built against coilbox's parts pack, and it
-- publishes through the gallery the way every other kind does.
--
-- The kind list moves with GALLERY_KINDS in lib/container/index.ts, as
-- 20260918190000_item_mod_project_kind.sql explains. The new list is a superset
-- of the old one, so no stored row can fail it.
alter table public.item drop constraint item_kind_check;

alter table public.item add constraint item_kind_check
  check (kind in ('preset', 'challenge', 'setup-pack', 'scenario', 'blueprint', 'mod-project', 'module'));

-- Geometry lifted from a game never goes on the hub, whatever game the upload
-- is tagged to. accept() in lib/gallery/publish.ts refuses it with
-- moduleGeometryRefusal() from the vendored container.ts, but authenticated
-- users hold an insert grant on this table and can write through PostgREST
-- without going near accept(). This check repeats the same rule so no write
-- path can store such a module.
--
-- The rule, matching moduleGeometryRefusal(): the payload must have a pieces
-- array, and no piece may carry a lifted object or a string meshId. A mesh with
-- no lifted record is refused too, because its record could have been lost.
-- Strict mode leaves a piece that is not an object unmatched, as the
-- TypeScript does. It applies when either the row or the envelope says module,
-- since coilbox imports by the envelope's kind.
--
-- The old kind check refused every row of kind module. A stored row of another
-- kind whose envelope says module would fail this check and stop the migration,
-- which is what should happen to it.
alter table public.item add constraint item_module_has_no_game_geometry check (
  (kind <> 'module' and container ->> 'kind' <> 'module')
  or (
    -- A check passes on null, so a missing pieces key has to read as false.
    jsonb_typeof(container #> '{payload,pieces}') is not distinct from 'array'
    and not coalesce(
      jsonb_path_exists(
        container,
        'strict $.payload.pieces[*] ? (@.lifted.type() == "object" || @.meshId.type() == "string")',
        '{}',
        true
      ),
      true
    )
  )
);
