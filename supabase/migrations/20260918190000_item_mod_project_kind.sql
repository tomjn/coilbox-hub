-- Mod projects, the sixth kind the gallery carries (issue #323). A project is
-- a named set of edits against one game's unit table, made in coilbox's unit
-- workshop, and it publishes through the gallery the way every other kind
-- does.
--
-- `kind` is a literal list rather than an enum precisely so this is a one line
-- widening, as the original migration says. It stays a list: adding a kind to
-- GALLERY_KINDS in lib/container/index.ts without adding it here means the code
-- accepts a share code the database then refuses, so the two have to move
-- together.
--
-- Rewriting the check revalidates every existing row, which is what should
-- happen: the new list is a superset of the old one, so nothing already stored
-- can fail it.
alter table public.item drop constraint item_kind_check;

alter table public.item add constraint item_kind_check
  check (kind in ('preset', 'challenge', 'setup-pack', 'scenario', 'blueprint', 'mod-project'));
