-- A second thing a yes may rest on: a decision, rather than a licence grant
-- (issue #121).
--
-- 20260814150000 made saying yes conditional on `licence` being non-null, so
-- that no row could allow everything and cite nothing. The rule is right and
-- stays. What was wrong was the assumption behind it, that the only reason the
-- hub would ever publish something is that a licence said it could.
--
-- The three Recoil games 20260814150100 researched are the counterexample. Two
-- of them state nothing at all, and the third states two things that
-- contradict each other. There is no licence value that is both truthful and
-- permissive for any of them, and the maintainer nevertheless decided on
-- 2026-08-14 to publish, on the grounds that the rest of the community already
-- does. That is a real basis and a defensible one. It is just not a licence.
--
-- So the constraint widens rather than goes. An `allowed` row still has to
-- point at something. It may now point at a decision instead of a document,
-- and the alternative - writing a permissive `licence` value the research never
-- found - would have made the table lie in the one column a takedown request
-- gets answered from.
--
-- `decision` is free text for the same reason `licence` is. It carries who
-- decided, on what grounds, and in whose words, because a year from now the
-- grounds are the only part that can be argued with. `decided_at` is separate
-- from `checked_at` because they age differently: research goes stale when the
-- upstream project relicences, a decision goes stale when the maintainer
-- changes his mind.

alter table public.asset_licence
  add column decision text check (length(btrim(decision)) between 1 and 4096),
  add column decided_at timestamptz;

-- A decision with no date is half a record. Either both or neither.
alter table public.asset_licence
  add constraint asset_licence_decision_pair_check
    check (num_nonnulls(decision, decided_at) <> 1);

alter table public.asset_licence
  drop constraint asset_licence_evidence_check;

-- Unchanged in substance: saying yes has to say what the yes rests on, and
-- saying no needs no evidence because refusing publishes nothing. The only new
-- word is `decision`.
alter table public.asset_licence
  add constraint asset_licence_evidence_check check (
    licence is not null
    or decision is not null
    or (redistribute_extracted <> 'allowed' and redistribute_rendered <> 'allowed')
  );
