export const OAUTH_PROVIDER_KEY = 'adplatform_oauth_provider';
export const SUPPORTED_OAUTH_PROVIDERS = ['meta', 'google', 'tiktok', 'snapchat'] as const;
export type OAuthProvider = (typeof SUPPORTED_OAUTH_PROVIDERS)[number];

export interface OAuthCallbackParams {
  provider: OAuthProvider;
  code: string;
  state: string;
}

export interface OAuthCallbackError {
  error: string;
}

/**
 * Reads the provider redirect (?code|auth_code=...&state=...). The provider is the one remembered when the
 * connect flow started; it is never taken from the URL. Returns null when this is not an OAuth return.
 */
export function parseOAuthCallback(
  search: string,
  storage: Pick<Storage, 'getItem'>,
): OAuthCallbackParams | OAuthCallbackError | null {
  const q = new URLSearchParams(search);
  const code = q.get('code') ?? q.get('auth_code');
  const state = q.get('state');
  const err = q.get('error');
  if (!code && !err) return null;
  if (err) return { error: q.get('error_description') || err };
  const provider = storage.getItem(OAUTH_PROVIDER_KEY) as OAuthProvider | null;
  if (!provider || !SUPPORTED_OAUTH_PROVIDERS.includes(provider)) {
    return { error: 'The sign-in return could not be matched to a connection you started. Start the connection again.' };
  }
  if (!code || !state) return { error: 'The provider return was missing the code or state.' };
  return { provider, code, state };
}
