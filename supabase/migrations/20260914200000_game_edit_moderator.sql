-- A moderator may change anything about a game an owner may (#350).
--
-- Most games have no owner, so until now nobody could give them words or a
-- snippet. These two policies sit beside the owner ones from
-- 20260821130000_game_ownership.sql. Permissive policies OR together, so an
-- update goes through for the owner or for a moderator.
--
-- The column grants from that migration are untouched. A moderator writes the
-- same columns an owner does (display name, description, links, snippet) and
-- nothing else: identity, images and ownership stay behind the secret key.
--
-- A hidden game is still reachable, because game_read_visible in
-- 20260821140000_game_visibility.sql already lets a moderator read one.

create policy game_edit_moderator
  on public.game
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());

create policy game_unit_snippet_moderator
  on public.game_unit
  for update to authenticated
  using (public.is_moderator())
  with check (public.is_moderator());
