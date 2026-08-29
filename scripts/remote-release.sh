#!/usr/bin/env bash
# Run on the VPS / EC2 host after code has been synced.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export HOME="${HOME:-/var/www}"
export COMPOSER_HOME="${COMPOSER_HOME:-/var/www/.composer}"
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME" "$HOME"
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

git config --global --add safe.directory "$ROOT" 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

ensure_php_841() {
  if php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' 2>/dev/null; then
    return 0
  fi
  echo "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown) < 8.4.1; installing php8.4 (composer.lock requires it)."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring \
    php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-tokenizer
  if [[ -x /usr/bin/php8.4 ]]; then
    update-alternatives --install /usr/bin/php php /usr/bin/php8.4 84 || true
    update-alternatives --set php /usr/bin/php8.4 || true
  fi
  sed -i 's/php8\.3-fpm\.sock/php8.4-fpm.sock/g' /etc/nginx/sites-available/* 2>/dev/null || true
  systemctl enable --now php8.4-fpm
  systemctl disable --now php8.3-fpm 2>/dev/null || true
  nginx -t && systemctl reload nginx || true
}

ensure_php_841

if [[ -z "${LOAD_ENV_FROM_S3:-}" && -f "$ROOT/.env" ]]; then
  LOAD_ENV_FROM_S3="$(python3 "$ROOT/scripts/patch-dotenv.py" "$ROOT/.env" --get LOAD_ENV_FROM_S3 || true)"
fi
export LOAD_ENV_FROM_S3="${LOAD_ENV_FROM_S3:-false}"
if [[ "${LOAD_ENV_FROM_S3:-false}" =~ ^(1|true|yes|on)$ ]]; then
  echo "Pulling env overlay from S3 (LOAD_ENV_FROM_S3=${LOAD_ENV_FROM_S3})."
  bash "$ROOT/scripts/pull-env-from-s3.sh"
else
  echo "Skipping S3 env overlay (LOAD_ENV_FROM_S3=${LOAD_ENV_FROM_S3:-false})."
fi

if [[ ! -f "$ROOT/.env" ]]; then
  echo "Missing .env. Enable pull_env_from_s3 or place .env on the server first." >&2
  exit 1
fi

# EC2 nginx site only: HTTP→HTTPS + Let's Encrypt (no-op on VPS).
bash "$ROOT/scripts/ensure-https.sh"

# EC2: quote DB password from Secrets Manager and GRANT bells_sis_app@'%'.
# No-op on VPS. Unquoted #/$ in .env otherwise becomes SQLSTATE 1045.
bash "$ROOT/scripts/sync-rds-credentials.sh"

# EC2: APP_KEY from Secrets Manager. Always validate 32-byte AES-256 length.
# Cached config.php with a bad key must be removed before any artisan boot.
bash "$ROOT/scripts/sync-app-key.sh"
python3 "$ROOT/scripts/patch-dotenv.py" "$ROOT/.env" --ensure-spa-session
rm -f "$ROOT/bootstrap/cache/config.php" \
  "$ROOT/bootstrap/cache/routes.php" \
  "$ROOT/bootstrap/cache/routes-v7.php" \
  "$ROOT/bootstrap/cache/events.php"

if [[ -f "$ROOT/vendor/autoload.php" ]]; then
  php artisan down --retry=60 || true
fi

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

php artisan migrate --force
php artisan storage:link --relative --force 2>/dev/null || php artisan storage:link --force || true
# Do not route:cache — Sanctum skips csrf-cookie when routes are cached, and PHP-FPM
# opcache (validate_timestamps=0) will keep a stale routes-v7.php until reload.
php artisan optimize --except=routes
php artisan route:clear
if ! php artisan route:list --path=csrf-cookie --json | grep -q csrf-cookie; then
  echo "FATAL: csrf-cookie route missing after release." >&2
  php artisan route:list --path=sanctum || true
  exit 1
fi
APP_ENV_VAL="$(python3 "$ROOT/scripts/patch-dotenv.py" "$ROOT/.env" --get APP_ENV || true)"
if [[ "${APP_ENV_VAL}" == "production" ]]; then
  php artisan production:check
fi
php artisan queue:restart || true
supervisorctl restart bells-sis-queue bells-sis-scheduler 2>/dev/null || true
systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true

php artisan up
chown -R www-data:www-data "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/vendor" 2>/dev/null || true
echo "API release complete."
