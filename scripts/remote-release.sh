#!/usr/bin/env bash
# Run on the VPS / EC2 host after code has been synced.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export HOME="${HOME:-/var/www}"
export COMPOSER_HOME="${COMPOSER_HOME:-/var/www/.composer}"
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

if [[ "${LOAD_ENV_FROM_S3:-false}" =~ ^(1|true|yes|on)$ ]]; then
  ENV_S3_FORCE=true bash "$ROOT/scripts/pull-env-from-s3.sh"
fi

if [[ ! -f "$ROOT/.env" ]]; then
  echo "Missing .env. Enable pull_env_from_s3 or place .env on the server first." >&2
  exit 1
fi

if [[ -f "$ROOT/vendor/autoload.php" ]]; then
  php artisan down --retry=60 || true
fi

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

php artisan migrate --force
php artisan storage:link --relative --force 2>/dev/null || php artisan storage:link --force || true
php artisan optimize
php artisan queue:restart || true
supervisorctl restart bells-sis-queue bells-sis-scheduler 2>/dev/null || true

php artisan up
chown -R www-data:www-data "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/vendor" 2>/dev/null || true
echo "API release complete."
