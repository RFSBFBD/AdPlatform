/**
 * Preview sessions are a fake, client-only sign-in used for UI demos.
 * They never prove real authentication, so they are OFF by default and must be
 * switched on explicitly in a demo build. Real sessions always come from the backend.
 */
export const ALLOW_PREVIEW_SESSION = false;
export const PREVIEW_TOKEN_PREFIX = 'preview_cloud_token_';
