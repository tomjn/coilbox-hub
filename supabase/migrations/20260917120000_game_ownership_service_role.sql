-- The decision action reads and updates an ownership request as service_role
-- (app/games/actions.ts, decideRequest), but 20260821130000 revoked every
-- privilege from service_role and never granted any back. Every approval and
-- decline failed with "permission denied" and the action returned without a
-- word, so the queue could not be worked at all.

grant select, update on public.game_ownership_request to service_role;
