/**
 * The OAuth providers the hub offers, in the order to offer them. Each must have
 * an `[auth.external.*]` block in supabase/config.toml and be enabled on the
 * hosted project, because the hub cannot read the Supabase auth settings at
 * runtime.
 */
export const SIGN_IN_PROVIDERS = [
  { id: "discord", label: "Discord" },
  { id: "github", label: "GitHub" },
  { id: "twitch", label: "Twitch" },
] as const;

export type SignInProviderId = (typeof SIGN_IN_PROVIDERS)[number]["id"];

/**
 * A provider id from a form field or a query string, or null for anything not
 * on the list. The value ends up in the URL the browser is sent to, so it is
 * checked against the list rather than passed through to Supabase.
 */
export function signInProvider(value: string | null | undefined): SignInProviderId | null {
  return SIGN_IN_PROVIDERS.find((provider) => provider.id === value)?.id ?? null;
}
