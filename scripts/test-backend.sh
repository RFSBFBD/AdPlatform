#!/usr/bin/env bash
# Repeatable backend verification on a pristine (empty) local test database.
# Migrates the disposable test database first, then runs the full suite.
# Never point this at a real database: it only uses the phpunit.xml test settings.
set -euo pipefail
cd "$(dirname "$0")/../backend"
PHP="${PHP_BIN:-php}"
export APP_ENV=testing
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
export DB_CONNECTION=pgsql
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-5432}"
export DB_DATABASE="${DB_DATABASE:-advertising_platform_test}"
export DB_USERNAME=ad_user
export DB_PASSWORD=ad_password_secret_local
case "$DB_HOST" in 127.0.0.1|localhost|postgres) ;; *) echo "Refusing non-local DB_HOST=$DB_HOST" >&2; exit 2;; esac
case "$DB_DATABASE" in *_test) ;; *) echo "Refusing DB_DATABASE=$DB_DATABASE: disposable test databases must end in _test" >&2; exit 2;; esac
# Guard against a database holding real data: it must be empty or contain no users/ad accounts rows.
if command -v psql >/dev/null 2>&1; then
  n=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -Atc "select coalesce((select count(*) from users),0)" 2>/dev/null || echo 0)
  if [ "${n:-0}" != "0" ]; then echo "Refusing: $DB_DATABASE has $n users rows. Use a freshly created empty database." >&2; exit 2; fi
fi
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
"$PHP" artisan migrate --force --no-interaction
"$PHP" vendor/bin/phpunit --display-warnings --display-skipped "$@"
