export const OAUTH_PROVIDER_KEY = 'adplatform_oauth_provider';
export const SUPPORTED_OAUTH_PROVIDERS = ['meta', 'google', 'tiktok', 'snapchat', 'pinterest', 'reddit', 'spotify', 'linkedin', 'microsoft', 'amazon', 'x'] as const;
export type OAuthProvider = (typeof SUPPORTED_OAUTH_PROVIDERS)[number];

export interface OAuthCallbackParams {
  provider: OAuthProvider;
  code: string;
  state: string;
  oauth_token?: string;
  oauth_verifier?: string;
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
  if (!code && !err && !q.has('oauth_token') && !q.has('denied')) return null;
  if (q.has('denied')) return {error: 'X authorization was declined.'};
  if (err) return { error: q.get('error_description') || err };
  const provider = storage.getItem(OAUTH_PROVIDER_KEY) as OAuthProvider | null;
  if (!provider || !SUPPORTED_OAUTH_PROVIDERS.includes(provider)) {
    return { error: 'The sign-in return could not be matched to a connection you started. Start the connection again.' };
  }
  if(provider==='x') {
    const oauth1State=state || storage.getItem('adplatform_x_oauth_state');
    const token=q.get('oauth_token'), verifier=q.get('oauth_verifier');
    if(!token || !verifier || !oauth1State) return {error:'X callback token, verifier and state are required.'};
    return {provider,code:'',state:oauth1State,oauth_token:token,oauth_verifier:verifier};
  }
  if (!code || !state) return { error: 'The provider return was missing the code or state.' };
  return { provider, code, state };
}
