/**
 * Per-platform connection guides. Every link below was read on the official site on 2026-11-22.
 * `built` says whether this codebase has a working adapter (code only, never run against a live provider).
 */
export interface ProviderGuide {
  id: 'meta' | 'google' | 'tiktok' | 'snapchat' | 'x';
  built: boolean;
  portalLabel: string;
  portalUrl: string;
  docs: { label: string; url: string }[];
  steps: string[];
  needs: string[];
  limits: string[];
}

export const PROVIDER_GUIDES: Record<ProviderGuide['id'], ProviderGuide> = {
  x: {
    id:'x',built:true,portalLabel:'X OAuth API reference',portalUrl:'https://docs.x.com/resources/fundamentals/authentication/api-reference.md',
    docs:[{label:'Ads analytics contract',url:'https://docs.x.com/x-ads-api/analytics.md'}],
    steps:['Use an Ads API approved application with OAuth1 consumer key and secret, not an OAuth2 client credential.','Register the exact allowed /accounts callback URL in X developer settings and this deployment.','Save consumer credentials in the admin panel, connect as the account owner, then select discovered accounts. The request token/secret is held encrypted for10minutes.'],
    needs:['Approved Ads API application access','User access to advertising accounts with known timezone and funding currency'],
    limits:['Only campaign delivery for1-7 completed account-local days on ALL_ON_TWITTER. No purchase revenue import or OAuth2 refresh.','Form token examples conflict with the JSON response table; both parse paths have fixture tests, not live proof.','Null metrics and overlapping segments abstain. Funding currencies are rechecked before reporting.']
  },
  tiktok: {
    id: 'tiktok',
    built: true,
    portalLabel: 'TikTok API for Business portal',
    portalUrl: 'https://business-api.tiktok.com/portal',
    docs: [
      { label: 'Register as a developer', url: 'https://business-api.tiktok.com/portal/docs/register-as-a-developer/v1.3' },
      { label: 'Create a developer app', url: 'https://business-api.tiktok.com/portal/docs/create-a-developer-app/v1.3' },
      { label: 'Advertiser authorization', url: 'https://business-api.tiktok.com/portal/docs/marketing-api-authorization/v1.3' },
    ],
    steps: [
      'Open the portal, sign in with a TikTok For Business account and choose Become a Developer.',
      'After the developer profile is approved, choose Create an App for the Marketing API (not TikTok Shop).',
      'Set the Advertiser redirect URL to this platform\'s callback address and pick read scopes for campaigns and reports.',
      'Save the App ID and Secret in Step 1 (admin only) on this page, then press Connect and approve as the advertiser.',
    ],
    needs: ['A TikTok For Business account', 'A company email on a verified company domain and a public company website (registration asks for both)'],
    limits: ['TikTok Shop Partner Center is a different product and its keys do not work here.', 'Developer and app approval is manual and its duration is not published.'],
  },
  meta: {
    id: 'meta',
    built: true,
    portalLabel: 'Meta: Ads Management Standard Access (official page)',
    portalUrl: 'https://developers.facebook.com/docs/features-reference/ads-management-standard-access/',
    docs: [
      { label: 'Ads Management Standard Access', url: 'https://developers.facebook.com/docs/features-reference/ads-management-standard-access/' },
      { label: 'Permissions reference', url: 'https://developers.facebook.com/docs/permissions/' },
    ],
    steps: [
      'Create a Meta developer app and add the Marketing API.',
      'Link the app to the Business that owns the ad account.',
      'Save the App ID and Secret in Step 1 (admin only) on this page, then press Connect and approve with a user who has access to the ad account.',
    ],
    needs: ['A Meta developer app', 'A user with access to the ad account'],
    limits: ['Reading data that you do not own needs Meta App Review and Business Verification (Advanced Access).', 'Your own ad accounts can work in Standard Access; check the page above.'],
  },
  google: {
    id: 'google',
    built: true,
    portalLabel: 'Google Ads API quick start',
    portalUrl: 'https://developers.google.com/google-ads/api/docs/get-started/make-first-call',
    docs: [{ label: 'Quick start (developer token, OAuth, manager account)', url: 'https://developers.google.com/google-ads/api/docs/get-started/make-first-call' }],
    steps: [
      'Create a Google Ads manager account (needed to get a developer token) and request the token in API Center.',
      'Create a Google Cloud project and OAuth credentials.',
      'Save the client ID, secret and developer token in Step 1 (admin only) on this page, then press Connect.',
    ],
    needs: ['A Google Ads manager account', 'A working company website URL and a monitored API contact email for the token application'],
    limits: ['The developer token has an access level that limits daily calls.', 'When access is through a manager account, its customer ID is required.'],
  },
  snapchat: {
    id: 'snapchat',
    built: true,
    portalLabel: 'Snap Business Manager (OAuth app)',
    portalUrl: 'https://businesshelp.snapchat.com/s/article/api-apply?language=en_GB',
    docs: [
      { label: 'Accessing the Marketing API', url: 'https://businesshelp.snapchat.com/s/article/api-apply?language=en_GB' },
      { label: 'Authentication', url: 'https://developers.snap.com/marketing-api/Ads-API/authentication' },
      { label: 'Measurement (stats)', url: 'https://developers.snap.com/marketing-api/Ads-API/measurement' },
    ],
    steps: [
      'In Ads Manager open Business Dashboard, then Business Details, then + OAuth App, accept the terms and Save.',
      'Enter a name and the redirect URI. The client secret is shown once only, so keep it.',
      'Authorization uses accounts.snapchat.com with scope snapchat-marketing-api. Access tokens last 60 minutes and need a refresh token.',
    ],
    needs: ['A Snap business account and an Organization Admin role', 'An Organization and an Ad Account in Business Manager'],
    limits: ['Reports are account-level daily only (no campaign breakdown yet).', 'Purchase value exists only for ads tracked with Snap Pixel; without it revenue shows as 0 and Snapchat is left out of the OR model with a reason.', 'Snapchat reports swipe-ups, which are stored in the clicks column and are not link clicks.', 'Spend is returned in micro-currency of the ad account and is converted to normal units.'],
  },
};
