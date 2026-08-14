-- What the three Recoil ecosystem games actually publish about their art, read
-- out of their own repositories on 2026-08-14 (issue #97).
--
-- Every row leaves both permissions at `unknown`, which blocks. That is
-- deliberate rather than unfinished. The research can say what a licence file
-- says. Only the maintainer decides what this hub is willing to put in a public
-- git repository whose history is permanent, and none of the three answers
-- below is clear enough to make that decision for him. Writing the findings
-- down now means the decision gets made once, from the evidence, instead of
-- being re-argued from memory every time somebody wonders.
--
-- `on conflict do nothing`, so rebuilding a database where the maintainer has
-- already ruled on one of these does not revert his decision to `unknown` and
-- re-present the question as unsettled.
--
-- The dates are literal rather than now(). A check made on 2026-08-14 does not
-- become a check made today because somebody rebuilt the database, and licence
-- claims age: any of these projects can relicence at any time.
--
-- Keyed on the modinfo shortname, read from each game's own modinfo.lua rather
-- than assumed. Beyond All Reason's is `BYAR`, not `BAR`, and its modinfo says
-- why on the same line: "'BAR' is used by original bar project still". If a
-- published item turns out to carry `BAR`, that is a second shortname for the
-- same game and wants its own row rather than an edit to this one.

insert into public.asset_licence
  (game, licence, licence_url, checked_at, checked_by, notes)
values
  (
    'BYAR',
    'Mixed. CC-BY-NC-ND-4.0, CC-BY-SA-4.0 and all rights reserved, by directory',
    'https://github.com/beyond-all-reason/Beyond-All-Reason/blob/master/LICENSE.md',
    '2026-08-14T00:00:00Z',
    'claude agent, issue #97',
    $note$Buildpics and renders differ here, which is the case the two permission columns exist for.

Buildpics. license_unitpics.txt covers the whole of /unitpics/, 1500 files named after unit defs, in one sentence: "All Unit icons, including subfolders, in /unitpics/ are copyrighted by IceXuick and Floris, made for the game Beyond All Reason (CC BY NC ND)". Plain CC BY-NC-ND permits verbatim noncommercial redistribution with attribution, so read alone that is a yes with credit to IceXuick and Floris. LICENSE.md then adds, in its models and textures paragraph, that the licence "does not permit any derivative work, which includes, but is not limited to: mods, mutators, repackaging, and taking any artwork and including it or its derivative in any other game, or distribution outside of BAR". The last clause bans redistribution outright and contradicts the CC grant. It sits in the models paragraph rather than the unitpics one, so whether it reaches buildpics at all is a reading of sentence scope, not a stated fact. This wants asking rather than deciding.

Renders. objects3d/Units/license.txt licences each model by the "artist" tag in its units/*.lua. Models by Cremuss are CC-BY-SA-4.0. Everything else is CC-BY-NC-ND-4.0 to Beherith. ND bars derivative works and a render is one, so a blanket yes for this game is wrong. A correct answer is per unit, by reading the artist tag, and this table holds one answer per game.

Also relevant: license_icons.txt and license_bitmaps.txt cover other extractable images and are stricter still, and LICENSE.md ends "Otherwise: all rights reserved."$note$
  ),
  (
    'BA',
    null,
    'https://github.com/Balanced-Annihilation/Balanced-Annihilation',
    '2026-08-14T00:00:00Z',
    'claude agent, issue #97',
    $note$No licence anywhere. Checked the GitHub root listing, the full recursive git tree for licen, copyright, copying and readme, the Balanced-Annihilation-106 branch, and the GitLab home the repo description now points at, gitlab.com/balanced-annihilation/Balanced-Annihilation, whose API reports license: null. The only hit was lups/readme.txt, the bundled Lups particle library, which is not a game licence.

So there is no grant of any kind over unitpics or models. That is an absence rather than a refusal, and no amount of further reading fixes it. Somebody has to ask the project.

Underneath that sits a second question. The unitpics carry Total Annihilation lineage names such as armaak.dds, and which files are Cavedog originals against community work was not checked.$note$
  ),
  (
    'XTA',
    null,
    'https://github.com/xta-springrts/xta-springrts',
    '2026-08-14T00:00:00Z',
    'claude agent, issue #97',
    $note$Nothing covers unitpics or models. The only licence in the tree is bitmaps/licence.txt, over roughly twenty named projectile textures, and it grants nothing by default: "These works may be used in any mod but only with my express permission and with credit given. Under no situation is there to be profit made from these works!", by Luke Cieron Fahy, Don_Gizmo.

The repo is the 2019 export of the code.google.com project and has had no push since, so there is nobody obviously maintaining it to ask.

Its modinfo declares game='Total Annihilation', and Spring distribution history treated XTA as requiring the player to own Total Annihilation, which is why the GPL installer shipped Nanoblobs in its place. Which unitpics are Cavedog originals against community work was not checked.$note$
  )
on conflict do nothing;
