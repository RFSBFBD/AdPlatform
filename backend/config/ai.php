<?php

/*
 * allow_fake_provider enables canned AI output for local demos. It is ignored in production and is
 * never inferred from a user's key. Tests run with APP_ENV=testing, which enables it implicitly.
 */
return [
    'allow_fake_provider' => (bool) env('AI_ALLOW_FAKE_PROVIDER', false),
];
