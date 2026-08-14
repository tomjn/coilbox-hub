-- Close the two open vocabularies on public.asset (issue #117).
--
-- 20260814090000 left `origin` and `approval_source` as free text because only
-- one writer existed and a guessed list would have refused a legitimate row.
-- Four writers are now being written at once (#104, #105, #110, #114), and four
-- independent guesses at a spelling is the failure mode: nothing errors, the
-- column just holds `extracted`, `extract`, `archive` and `seed` for one thing
-- and no query over it is ever right again. Cheaper to write the lists down
-- than to migrate rows written four ways, and the table is empty today.
--
-- Both are literal lists rather than enums, so widening either later is a one
-- line migration, the same as item_kind_check.
--
-- Each column already carries an inline length check under the name Postgres
-- generates for it, and a list of literals bounds the length by construction,
-- so the list replaces it rather than sitting next to it.

-- How the bytes were produced, not how they arrived. A coilbox client extracts
-- a buildpic and then uploads it: that is `extracted`, because the archive can
-- produce it again.
--
--   extracted  pulled out of a game or map archive
--   rendered   drawn from the unit's model, which is what render:<angle> is
--   uploaded   a person supplied the image themselves
--
-- The line that matters is `uploaded` against the other two. An extracted or
-- rendered asset can be re-derived and checked against its source archive; an
-- uploaded one is whatever bytes somebody chose, and it is the class the
-- moderation queue (#114) exists for. Both halves of that line have to be
-- writable for the distinction to mean anything, so all three are here.
alter table public.asset drop constraint asset_origin_check;

alter table public.asset add constraint asset_origin_check
  check (origin in ('extracted', 'rendered', 'uploaded'));

-- Which authority put the row in front of the public, once something did.
--
--   seed       the hand curated corpus, committed straight to the durable tier
--   bypass     the uploader held a capability that skips the queue
--   moderator  a person approved it in the moderation grid
--
-- `seed` and `bypass` are both bypasses and are still two values, for the
-- reason #101 splits can_seed_unit_assets from can_publish_unreviewed: seeding
-- content and waiving a safety control are different grants, and one column
-- that cannot tell them apart cannot answer who approved this. #115 needs that
-- answer to hold. The word `trusted` is deliberately not used, since that is
-- the collapsed flag #101 argues against.
alter table public.asset drop constraint asset_approval_source_check;

alter table public.asset add constraint asset_approval_source_check
  check (approval_source in ('seed', 'bypass', 'moderator'));

-- Tie the two moderation columns together, in one direction only.
--
-- An approved row has to say what approved it, otherwise the audit trail has a
-- hole in exactly the rows that are being served. A pending row has to say
-- nothing, otherwise `approval_source is not null` stops meaning approved.
--
-- A rejected row is left alone on purpose. A safety rejection over something a
-- moderator had already approved is a case #115 has to be able to demonstrate
-- afterwards, and forcing the update to null the column out would destroy the
-- record of who approved it as the price of recording the rejection. So on a
-- rejected row the column reads "how this was approved before it was
-- rejected", and is null if it never was.
alter table public.asset add constraint asset_approval_state_check
  check (
    case moderation
      when 'approved' then approval_source is not null
      when 'pending' then approval_source is null
      else true
    end
  );
