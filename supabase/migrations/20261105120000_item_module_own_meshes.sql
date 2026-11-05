-- A module may hold meshes the game maker imported from their own model file
-- (coilbox#4360). Such a piece records the file in ownMesh, and the payload
-- carries the meshes in geometry. Geometry lifted from a game is still refused,
-- and so is a mesh with neither record, whose record could have been lost.
--
-- The rule, matching moduleGeometryRefusal() in the vendored container.ts: the
-- payload must have a pieces array, no piece may carry a lifted object, a piece
-- with a string meshId must carry an ownMesh object whose file is a non-empty
-- string, and a payload with any such piece must carry a string geometry.
--
-- The two mesh tests run in lax mode, where a missing ownMesh is an empty
-- match rather than an error, which in strict mode would leave the piece
-- unmatched and so let it through. Each asks for an object first, so a number
-- or a string is passed over, as the TypeScript does. Lax mode does open a
-- piece that is an array and test what is inside, so it refuses a little more
-- than the TypeScript, never less.
--
-- Every stored module passed the old check, which refused any string meshId,
-- so every one passes this.
alter table public.item drop constraint item_module_has_no_game_geometry;

alter table public.item add constraint item_module_has_no_game_geometry check (
  (kind <> 'module' and container ->> 'kind' <> 'module')
  or (
    -- A check passes on null, so a missing pieces key has to read as false.
    jsonb_typeof(container #> '{payload,pieces}') is not distinct from 'array'
    and not coalesce(
      jsonb_path_exists(
        container,
        'strict $.payload.pieces[*] ? (@.lifted.type() == "object")',
        '{}',
        true
      ),
      true
    )
    and not coalesce(
      jsonb_path_exists(
        container,
        'lax $.payload.pieces[*] ? (@.type() == "object" && @.meshId.type() == "string" && !(@.ownMesh.type() == "object" && @.ownMesh.file.type() == "string" && @.ownMesh.file != ""))',
        '{}',
        true
      ),
      true
    )
    and (
      jsonb_typeof(container #> '{payload,geometry}') is not distinct from 'string'
      or not coalesce(
        jsonb_path_exists(
          container,
          'lax $.payload.pieces[*] ? (@.type() == "object" && @.meshId.type() == "string")',
          '{}',
          true
        ),
        true
      )
    )
  )
);
