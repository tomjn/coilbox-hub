import { expect, test } from "bun:test";
import type { UserIdentity } from "@supabase/supabase-js";
import {
  identityAccount,
  linkableProviders,
  linkFailureMessage,
  mayUnlink,
  signInMethods,
} from "./signInMethods";

function identity(provider: string, data: Record<string, unknown> = {}): UserIdentity {
  return {
    id: `${provider}-sub`,
    identity_id: `${provider}-identity`,
    user_id: "user",
    provider,
    identity_data: data,
  };
}

test("the last identity cannot be unlinked", () => {
  expect(mayUnlink([identity("discord")])).toBe(false);
  expect(mayUnlink([])).toBe(false);
});

test("either of two identities can be unlinked", () => {
  expect(mayUnlink([identity("discord"), identity("github")])).toBe(true);
});

test("only providers with nothing linked are offered for linking", () => {
  expect(linkableProviders([identity("discord")]).map((p) => p.id)).toEqual(["github", "twitch"]);
  expect(linkableProviders([identity("discord"), identity("github"), identity("twitch")])).toEqual(
    [],
  );
});

test("an identity from a provider the hub does not offer offers nothing extra", () => {
  expect(linkableProviders([identity("google")]).map((p) => p.id)).toEqual([
    "discord",
    "github",
    "twitch",
  ]);
});

test("the account name follows the moderator directory's order", () => {
  expect(identityAccount(identity("github", { user_name: "ada", name: "Ada L" }))).toBe("ada");
  expect(identityAccount(identity("github", { preferred_username: "pref", user_name: "ada" }))).toBe(
    "pref",
  );
  expect(identityAccount(identity("twitch", { name: "Ada", email: "a@example.com" }))).toBe("Ada");
});

test("blank and missing names fall through, and an address is the last resort", () => {
  expect(identityAccount(identity("discord", { full_name: "  ", email: "a@example.com" }))).toBe(
    "a@example.com",
  );
  expect(identityAccount(identity("discord"))).toBeNull();
  expect(identityAccount({ identity_data: undefined })).toBeNull();
});

test("each method carries the provider's label and the identity id unlinking needs", () => {
  expect(signInMethods([identity("github", { user_name: "ada" }), identity("google")])).toEqual([
    { identityId: "github-identity", provider: "github", label: "GitHub", account: "ada" },
    { identityId: "google-identity", provider: "google", label: "google", account: null },
  ]);
});

test("an identity already on another account gets its own sentence", () => {
  expect(linkFailureMessage("github", "identity_already_exists")).toContain(
    "already linked to another account",
  );
  expect(linkFailureMessage("twitch", "something_else")).toBe(
    "Could not link your Twitch account. Try again.",
  );
  expect(linkFailureMessage("discord", null)).toBe("Could not link your Discord account. Try again.");
});
