import { beforeEach, expect, mock, test } from "bun:test";

// The server actions behind enrolling, verifying and removing a second factor
// (#145). Supabase Auth is the authority on whether a code is right, so what is
// proved here is everything around it: which factor an action is allowed to
// touch, that a session which has not entered a code cannot remove one, and
// that the step up page only ever sends somebody to a path on this site.

const USER = "11111111-1111-1111-1111-111111111111";
const VERIFIED = "f0000000-0000-0000-0000-000000000001";
const UNVERIFIED = "f0000000-0000-0000-0000-000000000002";
const SOMEBODY_ELSES = "f0000000-0000-0000-0000-000000000099";

interface FakeFactor {
  id: string;
  factor_type: "totp";
  status: "verified" | "unverified";
  created_at: string;
  updated_at: string;
}

function factor(id: string, status: FakeFactor["status"]): FakeFactor {
  return { id, factor_type: "totp", status, created_at: "2026-10-17T00:00:00Z", updated_at: "2026-10-17T00:00:00Z" };
}

let claims: { sub: string; aal: string } | null = { sub: USER, aal: "aal1" };
let factors: FakeFactor[] = [];
let verifyError: { code?: string; message: string } | null = null;
let enrollError: { message: string } | null = null;
let unenrollError: { message: string } | null = null;

const enroll = mock<(params: { factorType: string }) => Promise<unknown>>(async () =>
  enrollError
    ? { data: null, error: enrollError }
    : {
        data: {
          id: "f0000000-0000-0000-0000-000000000003",
          type: "totp",
          totp: { qr_code: "data:image/svg+xml;utf-8,<svg/>", secret: "SECRET", uri: "otpauth://totp/x" },
        },
        error: null,
      },
);
const unenroll = mock(async (params: { factorId: string }) =>
  unenrollError ? { data: null, error: unenrollError } : { data: { id: params.factorId }, error: null },
);
const challengeAndVerify = mock<(params: { factorId: string; code: string }) => Promise<unknown>>(async () =>
  verifyError ? { data: null, error: verifyError } : { data: {}, error: null },
);

mock.module("@/lib/supabase/server", () => ({
  createClient: async () => ({
    auth: {
      getClaims: async () => ({ data: claims ? { claims } : null, error: null }),
      mfa: {
        listFactors: async () => ({
          data: { all: factors, totp: factors.filter((f) => f.status === "verified"), phone: [], webauthn: [] },
          error: null,
        }),
        enroll,
        unenroll,
        challengeAndVerify,
      },
    },
  }),
}));

const revalidated = mock<(path: string) => void>(() => {});
const realCache = await import("next/cache");
mock.module("next/cache", () => ({ ...realCache, revalidatePath: revalidated }));

const { confirmEnrolment, removeFactor, startEnrolment, verifySecondFactor } = await import(
  "./secondFactorActions"
);

function form(fields: Record<string, string>): FormData {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) data.set(key, value);
  return data;
}

beforeEach(() => {
  claims = { sub: USER, aal: "aal1" };
  factors = [];
  verifyError = null;
  enrollError = null;
  unenrollError = null;
  enroll.mockClear();
  unenroll.mockClear();
  challengeAndVerify.mockClear();
  revalidated.mockClear();
});

// Starting an enrolment.

test("a signed out visitor starting an enrolment is sent to sign in", async () => {
  claims = null;

  await expect(startEnrolment()).rejects.toMatchObject({
    digest: expect.stringContaining(";/publish;"),
  });
  expect(enroll).not.toHaveBeenCalled();
});

test("an account with no factor gets a QR code and the secret to type in instead", async () => {
  const state = await startEnrolment();

  expect(state).toEqual({
    enrolment: {
      factorId: "f0000000-0000-0000-0000-000000000003",
      qrCode: "data:image/svg+xml;utf-8,<svg/>",
      secret: "SECRET",
    },
  });
  expect(enroll).toHaveBeenCalledTimes(1);
  expect(enroll.mock.calls[0][0]).toMatchObject({ factorType: "totp" });
});

test("an enrolment left unfinished is cleared before a new one starts", async () => {
  factors = [factor(UNVERIFIED, "unverified")];

  await startEnrolment();

  expect(unenroll).toHaveBeenCalledWith({ factorId: UNVERIFIED });
  expect(enroll).toHaveBeenCalledTimes(1);
});

test("an account with a factor cannot add another without entering a code first", async () => {
  factors = [factor(VERIFIED, "verified")];

  const state = await startEnrolment();

  expect(state.error).toBeString();
  expect(state.enrolment).toBeUndefined();
  expect(enroll).not.toHaveBeenCalled();
  expect(unenroll).not.toHaveBeenCalled();
});

test("an account with a factor on an aal2 session can add a spare", async () => {
  factors = [factor(VERIFIED, "verified")];
  claims = { sub: USER, aal: "aal2" };

  const state = await startEnrolment();

  expect(state.enrolment).toBeDefined();
  expect(unenroll).not.toHaveBeenCalled();
});

test("a refused enrolment says so rather than drawing an empty QR code", async () => {
  enrollError = { message: "nope" };

  const state = await startEnrolment();

  expect(state.error).toBeString();
  expect(state.enrolment).toBeUndefined();
});

// Confirming it.

test("a code that matches finishes the enrolment and redraws the account page", async () => {
  factors = [factor(UNVERIFIED, "unverified")];

  const state = await confirmEnrolment({}, form({ factorId: UNVERIFIED, code: " 123 456 " }));

  expect(state).toEqual({ done: true });
  expect(challengeAndVerify).toHaveBeenCalledWith({ factorId: UNVERIFIED, code: "123456" });
  expect(revalidated).toHaveBeenCalledWith("/account");
});

test("a code that does not match says so and changes nothing", async () => {
  factors = [factor(UNVERIFIED, "unverified")];
  verifyError = { code: "mfa_verification_failed", message: "Invalid TOTP code entered" };

  const state = await confirmEnrolment({}, form({ factorId: UNVERIFIED, code: "000000" }));

  expect(state.error).toBeString();
  expect(state.done).toBeUndefined();
  expect(revalidated).not.toHaveBeenCalled();
});

test("something that is not digits never reaches Supabase", async () => {
  factors = [factor(UNVERIFIED, "unverified")];

  const state = await confirmEnrolment({}, form({ factorId: UNVERIFIED, code: "abcdef" }));

  expect(state.error).toBeString();
  expect(challengeAndVerify).not.toHaveBeenCalled();
});

test("a factor that is not this account's unfinished one cannot be confirmed", async () => {
  factors = [factor(UNVERIFIED, "unverified")];

  const state = await confirmEnrolment({}, form({ factorId: SOMEBODY_ELSES, code: "123456" }));

  expect(state.error).toBeString();
  expect(challengeAndVerify).not.toHaveBeenCalled();
});

// Removing one.

test("a session that has not entered a code cannot remove a factor", async () => {
  factors = [factor(VERIFIED, "verified")];

  const state = await removeFactor({}, form({ factorId: VERIFIED }));

  expect(state.error).toBeString();
  expect(unenroll).not.toHaveBeenCalled();
});

test("an aal2 session can remove its own factor", async () => {
  factors = [factor(VERIFIED, "verified")];
  claims = { sub: USER, aal: "aal2" };

  const state = await removeFactor({}, form({ factorId: VERIFIED }));

  expect(state).toEqual({});
  expect(unenroll).toHaveBeenCalledWith({ factorId: VERIFIED });
  expect(revalidated).toHaveBeenCalledWith("/account");
});

test("a factor that is not on this account is not removed", async () => {
  factors = [factor(VERIFIED, "verified")];
  claims = { sub: USER, aal: "aal2" };

  const state = await removeFactor({}, form({ factorId: SOMEBODY_ELSES }));

  expect(state.error).toBeString();
  expect(unenroll).not.toHaveBeenCalled();
});

test("a removal Supabase refuses says so", async () => {
  factors = [factor(VERIFIED, "verified")];
  claims = { sub: USER, aal: "aal2" };
  unenrollError = { message: "insufficient_aal" };

  const state = await removeFactor({}, form({ factorId: VERIFIED }));

  expect(state.error).toBeString();
  expect(revalidated).not.toHaveBeenCalled();
});

// Stepping up.

test("the right code sends a moderator back to the page that asked", async () => {
  factors = [factor(VERIFIED, "verified")];

  await expect(
    verifySecondFactor({}, form({ factorId: VERIFIED, code: "123456", next: "/moderation/maps" })),
  ).rejects.toMatchObject({ digest: expect.stringContaining(";/moderation/maps;") });
  expect(challengeAndVerify).toHaveBeenCalledWith({ factorId: VERIFIED, code: "123456" });
});

test("with one factor the form does not have to name it", async () => {
  factors = [factor(VERIFIED, "verified")];

  await expect(verifySecondFactor({}, form({ code: "123456", next: "/moderation" }))).rejects.toMatchObject({
    digest: expect.stringContaining(";/moderation;"),
  });
  expect(challengeAndVerify).toHaveBeenCalledWith({ factorId: VERIFIED, code: "123456" });
});

test("somewhere off this site is not somewhere to be sent", async () => {
  factors = [factor(VERIFIED, "verified")];

  await expect(
    verifySecondFactor({}, form({ code: "123456", next: "//evil.example/moderation" })),
  ).rejects.toMatchObject({ digest: expect.stringContaining(";/;") });
});

test("the wrong code stays on the page with a message", async () => {
  factors = [factor(VERIFIED, "verified")];
  verifyError = { code: "mfa_verification_failed", message: "Invalid TOTP code entered" };

  const state = await verifySecondFactor({}, form({ code: "000000", next: "/moderation" }));

  expect(state.error).toBeString();
});

test("an unfinished enrolment cannot be used to step up", async () => {
  factors = [factor(UNVERIFIED, "unverified")];

  const state = await verifySecondFactor({}, form({ factorId: UNVERIFIED, code: "123456", next: "/moderation" }));

  expect(state.error).toBeString();
  expect(challengeAndVerify).not.toHaveBeenCalled();
});

test("a signed out visitor stepping up is sent to sign in", async () => {
  claims = null;

  await expect(verifySecondFactor({}, form({ code: "123456" }))).rejects.toMatchObject({
    digest: expect.stringContaining(";/publish;"),
  });
});
