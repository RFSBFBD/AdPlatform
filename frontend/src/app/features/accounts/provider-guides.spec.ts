import { PROVIDER_GUIDES } from './provider-guides';

describe('provider guides', () => {
  const all = Object.values(PROVIDER_GUIDES);
  it('covers meta, google, tiktok and snapchat', () => {
    expect(all.map((g) => g.id).sort()).toEqual(['google', 'meta', 'snapchat', 'tiktok']);
  });
  it('uses only https links on official hosts', () => {
    const ok = ['business-api.tiktok.com', 'developers.facebook.com', 'developers.google.com', 'businesshelp.snapchat.com', 'developers.snap.com'];
    for (const g of all) {
      for (const u of [g.portalUrl, ...g.docs.map((d) => d.url)]) {
        const url = new URL(u);
        expect(url.protocol).toBe('https:');
        expect(ok).toContain(url.hostname);
      }
      expect(g.steps.length).toBeGreaterThan(0);
    }
  });
  it('states the Snapchat reporting limits', () => {
    const l = PROVIDER_GUIDES.snapchat.limits.join(' ');
    expect(l).toContain('account-level');
    expect(l).toContain('Snap Pixel');
    expect(l).toContain('swipe-ups');
  });
  it('tells TikTok users that Shop keys do not work', () => {
    expect(PROVIDER_GUIDES.tiktok.limits.join(' ')).toContain('TikTok Shop');
  });
});
