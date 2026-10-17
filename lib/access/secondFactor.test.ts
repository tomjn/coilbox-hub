import { beforeEach, expect, mock, test } from "bun:test";

// How a moderation page refuses somebody (#145). The database decides, and
// this proves the page turns each of its answers into the right place to send
// them, and that anything unexpected is a 404 rather than a way in.

let claims: { sub: string } | null = { sub: "abc" };
let rpcResult: { data: unknown; error: unknown } = { data: "granted", error: null };
const rpc = mock<(name: string, args?: unknown) => Promise<typeof rpcResult>>(async () => rpcResult);

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: { getClaims: async () => ({ data: claims ? { claims } : null }) },
    rpc,
  }),
}));

const { refuseModeration, stepUpPath, toCapabilityAccess } = await import("./secondFactor");

beforeEach(() => {
  claims = { sub: "abc" };
  rpcResult = { data: "granted", error: null };
  rpc.mockClear();
});

test("the four answers the database gives are read as themselves", () => {
  expect(toCapabilityAccess("granted")).toBe("granted");
  expect(toCapabilityAccess("verify")).toBe("verify");
  expect(toCapabilityAccess("enrol")).toBe("enrol");
  expect(toCapabilityAccess("none")).toBe("none");
});

test("anything else is none, so a surprise never reads as access", () => {
  expect(toCapabilityAccess(true)).toBe("none");
  expect(toCapabilityAccess(null)).toBe("none");
  expect(toCapabilityAccess("GRANTED")).toBe("none");
});

test("the step up page is told where to send them back to", () => {
  expect(stepUpPath("/moderation/maps/comet")).toBe(
    "/account/verify?next=%2Fmoderation%2Fmaps%2Fcomet",
  );
});

test("a moderator who has not entered a code is sent to enter one", async () => {
  rpcResult = { data: "verify", error: null };

  await expect(refuseModeration("/moderation/assets")).rejects.toMatchObject({
    digest: expect.stringContaining(";/account/verify?next=%2Fmoderation%2Fassets;"),
  });
  expect(rpc).toHaveBeenCalledWith("capability_access", { capability: "can_moderate" });
});

test("a moderator required to enrol is sent to the account page to do it", async () => {
  rpcResult = { data: "enrol", error: null };

  await expect(refuseModeration("/moderation")).rejects.toMatchObject({
    digest: expect.stringContaining(";/account#second-factor;"),
  });
});

test("somebody who holds nothing gets the same 404 as before", async () => {
  rpcResult = { data: "none", error: null };

  await expect(refuseModeration("/moderation")).rejects.toMatchObject({
    digest: expect.stringContaining("404"),
  });
});

test("an error from the database is a 404, not a redirect", async () => {
  rpcResult = { data: null, error: { message: "down" } };

  await expect(refuseModeration("/moderation")).rejects.toMatchObject({
    digest: expect.stringContaining("404"),
  });
});

test("a signed out visitor is not asked about and gets a 404", async () => {
  claims = null;

  await expect(refuseModeration("/moderation")).rejects.toMatchObject({
    digest: expect.stringContaining("404"),
  });
  expect(rpc).not.toHaveBeenCalled();
});

test("granted is still refused here, because the page's own check already said no", async () => {
  rpcResult = { data: "granted", error: null };

  await expect(refuseModeration("/moderation")).rejects.toMatchObject({
    digest: expect.stringContaining("404"),
  });
});
