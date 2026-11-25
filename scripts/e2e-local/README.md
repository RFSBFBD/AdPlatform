# Local browser-to-Laravel E2E (synthetic only)
Disposable local database whose name ends in `_e2e`; `seed.php` refuses anything else or a non-empty users table. Http is faked in-process: no provider, model or network call. Paths inside the scripts are for the machine that produced the evidence (/tmp/w/..., Chrome on port 9222) and need adapting.
1. Migrate the `_e2e` database, then `php seed.php` (users owner@e2e.invalid and other@e2e.invalid, password `password`, synthetic accounts and series).
2. `php evreq.php > evreq.json`, then POST it to /api/v1/analytics/decision-evidence as the owner to store one evidence snapshot.
3. Serve Laravel on 127.0.0.1:8000, run `proxy-server.mjs` (serves the built frontend on 4300 and proxies /api), then `node browser-e2e.mjs` against headless Chrome.
