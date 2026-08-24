#!/usr/bin/env bash
# Run on the VPS / EC2 host after code has been synced.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ "${LOAD_ENV_FROM_S3:-false}" =~ ^(1|true|yes|on)$ ]]; then
  ENV_S3_FORCE=true bash "$ROOT/scripts/pull-env-from-s3.sh"
fi

if [[ ! -f "$ROOT/.env" ]]; then
  echo "Missing .env. Enable pull_env_from_s3 or place .env on the server first." >&2
  exit 1
fi

php artisan down --retry=60 || true

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

php artisan migrate --force
php artisan storage:link --relative --force 2>/dev/null || php artisan storage:link --force || true
php artisan optimize
php artisan queue:restart || true
supervisorctl restart bells-sis-queue bells-sis-scheduler 2>/dev/null || true

php artisan up
echo "API release complete."
