-- The maintainer's answer to the three questions 20260814150100 left open
-- (issues #97 and #121).
--
-- On 2026-08-14 he decided, in his words: "other Vercel hosted sites in the BAR
-- community already use their unit pics, this is not something we need to worry
-- about". All three games are allowed, for extraction and for rendering.
--
-- Taken as covering Balanced Annihilation and XTA as well as Beyond All Reason,
-- because the reasoning is about what the surrounding community already does
-- rather than about any one game's licence file. Two of the three have no
-- licence file to reason from in the first place.
--
-- The research in 20260814150100 is not revised, only closed. What it found is
-- still what is there: an ND term that would bar renders, and two projects that
-- state nothing. None of that changed. What changed is that somebody with the
-- standing to decide decided, and the row now records both halves - `licence`
-- and `notes` say what was found, `decision` says why the hub publishes anyway.
-- Anyone reading this row later needs both to understand it, and would be
-- misled by either alone.

update public.asset_licence set
  redistribute_extracted = 'allowed',
  redistribute_rendered = 'allowed',
  decided_at = '2026-08-14T00:00:00Z',
  decision = $decision$Maintainer decision, 2026-08-14, in his words: "other Vercel hosted sites in the BAR community already use their unit pics, this is not something we need to worry about".

Note what this does not rest on. The CC BY-NC-ND over /unitpics/ and the per artist CC-BY-NC-ND-4.0 over the models both carry an ND term, and a render drawn from a model is a derivative work, so the licence on its own would refuse the renders. LICENSE.md would refuse everything. The basis is community practice and the maintainer's willingness to answer for it, not a grant.

The named authors are IceXuick and Floris for the buildpics, Beherith and Cremuss for the models.$decision$,
  notes = notes || $append$

Asked and decided on 2026-08-14. The open question above - whether LICENSE.md's "distribution outside of BAR" clause reaches the unitpics - no longer gates publication, because the answer does not turn on it. See the decision column.$append$
where game = 'BYAR';

update public.asset_licence set
  redistribute_extracted = 'allowed',
  redistribute_rendered = 'allowed',
  decided_at = '2026-08-14T00:00:00Z',
  decision = $decision$Maintainer decision, 2026-08-14, in his words: "other Vercel hosted sites in the BAR community already use their unit pics, this is not something we need to worry about".

Read as covering Balanced Annihilation because the reasoning is about community practice rather than about one game. There is no licence file here at all, so there is nothing for the decision to contradict, and equally nothing for it to rest on. `licence` stays null on purpose: the honest record is that nothing was found, not that something permissive was.$decision$,
  notes = notes || $append$

Still unasked as of 2026-08-14, and no longer blocking. The maintainer decided to publish without a grant. See the decision column.$append$
where game = 'BA';

update public.asset_licence set
  redistribute_extracted = 'allowed',
  redistribute_rendered = 'allowed',
  decided_at = '2026-08-14T00:00:00Z',
  decision = $decision$Maintainer decision, 2026-08-14, in his words: "other Vercel hosted sites in the BAR community already use their unit pics, this is not something we need to worry about".

Read as covering XTA because the reasoning is about community practice rather than about one game. Nothing in the tree grants anything over unitpics or models, and there is nobody obviously maintaining the 2019 export to ask, so `licence` stays null: nothing was found, and that is the finding.

bitmaps/licence.txt is unaffected. It covers roughly twenty projectile textures, is not a unitpic or a model, and still says what it said.$decision$,
  notes = notes || $append$

Decided on 2026-08-14 without a grant, there being nobody left to ask. See the decision column.$append$
where game = 'XTA';
