-- What actually protects a pending upload (issue #131). A correction to
-- 20260814180000_asset_access.sql, which is merged and therefore left alone.
--
-- That migration says the protection of a pending upload is that the hub never
-- hands out its URL, "and Blob's random path suffix is what makes an
-- undisclosed URL unguessable". There was no random suffix. `putBlobAsset` set
-- `addRandomSuffix: false` so that the path the row stored actually reached the
-- object, and #104 derived that path from the identity and the hash of the
-- encoded bytes. Both decisions were right on their own. Together they put
-- every pending object at a path anybody holding the bytes could compute, and
-- the uploader always holds the bytes. A modified client could upload anything,
-- work out its own object's URL and publish it, which is the failure #114 says
-- the queue exists to prevent.
--
-- So the claim is now true rather than aspirational. Blob adds the suffix, the
-- row stores the pathname Blob returned rather than the one the hub computed,
-- and neither upload route replies with the path or the URL. The derived,
-- content addressed path survives as the thing the hub asks for and the client
-- direct token is bound to, so nothing can write outside its own namespace, and
-- promotion (#111) recomputes it from the row when it writes the object into
-- the durable tier, which is what keeps that tier content addressed for #112.
--
-- The read policy in 20260814180000 is unchanged and is still the whole of the
-- protection. This only makes the sentence underneath it hold.

comment on column public.asset.path is
  'Tier relative, never a fully qualified URL. On the blob tier this is the pathname Blob returned, which carries a random suffix the uploader cannot derive: the store is public, so an unguessable path is what keeps a pending upload out of sight until it is approved (#131). Promotion rewrites it to the content addressed path when the row moves to the static tier.';
