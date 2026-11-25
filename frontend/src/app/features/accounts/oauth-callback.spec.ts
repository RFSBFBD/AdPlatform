import { parseOAuthCallback, OAUTH_PROVIDER_KEY } from './oauth-callback';

const store = (v: string | null) => ({ getItem: (k: string) => (k === OAUTH_PROVIDER_KEY ? v : null) });

describe('parseOAuthCallback', () => {
  it('ignores normal page loads', () => {
    expect(parseOAuthCallback('', store('meta'))).toBeNull();
  });
  it('parses code+state using the remembered provider', () => {
    expect(parseOAuthCallback('?code=abc&state=s1', store('meta'))).toEqual({ provider: 'meta', code: 'abc', state: 's1' });
  });
  it('accepts TikTok auth_code', () => {
    expect(parseOAuthCallback('?auth_code=zz&state=s2', store('tiktok'))).toEqual({ provider: 'tiktok', code: 'zz', state: 's2' });
  });
  it('never trusts a provider from the URL', () => {
    const r: any = parseOAuthCallback('?code=abc&state=s&provider=google', store(null));
    expect(r.error).toBeTruthy();
  });
  it('rejects unknown remembered provider', () => {
    expect((parseOAuthCallback('?code=a&state=s', store('unknown')) as any).error).toBeTruthy();
  });
  it('surfaces provider errors', () => {
    expect((parseOAuthCallback('?error=access_denied&error_description=No', store('meta')) as any).error).toBe('No');
  });
  it('requires state', () => {
    expect((parseOAuthCallback('?code=a', store('meta')) as any).error).toBeTruthy();
  });
});

describe('X OAuth1 callback', () => {
  it('binds token/verifier to remembered X and state', () => {
    const storage={getItem:()=> 'x'};
    const r=parseOAuthCallback('?oauth_token=t&oauth_verifier=v&state=s',storage);
    expect(r).toEqual({provider:'x',code:'',state:'s',oauth_token:'t',oauth_verifier:'v'});
    expect(parseOAuthCallback('?oauth_token=t&state=s',storage)).toHaveProperty('error');
    expect(parseOAuthCallback('?denied=t',storage)).toHaveProperty('error');
  });
});
