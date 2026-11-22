<?php

/*
 * Exact redirect URIs the server will hand to OAuth providers. They must also be registered with each
 * provider. Comma-separated env, e.g. OAUTH_ALLOWED_REDIRECT_URIS=https://app.example.com/accounts
 * Empty (default) = all redirects refused.
 */
return [
    'allowed_redirect_uris' => array_values(array_filter(array_map('trim', explode(',', (string) env('OAUTH_ALLOWED_REDIRECT_URIS', ''))))),
];
