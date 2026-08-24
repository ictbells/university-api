#!/usr/bin/env bash
# On EC2: rewrite APP_KEY in .env from Secrets Manager (quoted, 32-byte AES-256 key).
# Skips fetch on VPS; still normalizes/validates the local APP_KEY.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH="$ROOT/scripts/patch-dotenv.py"
ENV_FILE="${ENV_FILE:-$ROOT/.env}"
REGION="${AWS_REGION:-${AWS_DEFAULT_REGION:-}}"
APP_KEY_SECRET="${AWS_APP_KEY_SECRET:-}"

is_ec2() {
  local token
  token="$(curl -sf --max-time 2 -X PUT "http://169.254.169.254/latest/api/token" \
    -H "X-aws-ec2-metadata-token-ttl-seconds: 60" 2>/dev/null)" || return 1
  curl -sf --max-time 2 -H "X-aws-ec2-metadata-token: $token" \
    "http://169.254.169.254/latest/meta-data/instance-id" >/dev/null 2>&1
}

imds_region() {
  local token
  token="$(curl -sf --max-time 2 -X PUT "http://169.254.169.254/latest/api/token" \
    -H "X-aws-ec2-metadata-token-ttl-seconds: 60" 2>/dev/null)" || return 1
  curl -sf --max-time 2 -H "X-aws-ec2-metadata-token: $token" \
    "http://169.254.169.254/latest/meta-data/placement/region"
}

env_get() {
  python3 "$PATCH" "$ENV_FILE" --get "$1"
}

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

if [[ "${SYNC_APP_KEY:-auto}" =~ ^(0|false|no|off)$ ]]; then
  python3 "$PATCH" "$ENV_FILE" --normalize-app-key --check-app-key
  exit $?
fi

pull_from_secrets=false
if [[ "${SYNC_APP_KEY:-auto}" =~ ^(1|true|yes|on)$ ]]; then
  pull_from_secrets=true
elif is_ec2; then
  pull_from_secrets=true
fi

if [[ "$pull_from_secrets" == true ]]; then
  if [[ -z "$REGION" ]]; then
    REGION="$(env_get AWS_DEFAULT_REGION || true)"
  fi
  if [[ -z "$REGION" ]]; then
    REGION="$(imds_region || true)"
  fi
  REGION="${REGION:-us-east-1}"

  if [[ -z "$APP_KEY_SECRET" ]]; then
    APP_KEY_SECRET="$(env_get AWS_APP_KEY_SECRET || true)"
  fi
  if [[ -z "$APP_KEY_SECRET" ]]; then
    rds_secret="$(env_get AWS_RDS_APP_SECRET || true)"
    if [[ "$rds_secret" == */rds/app ]]; then
      APP_KEY_SECRET="${rds_secret%/rds/app}/app-key"
    fi
  fi
  APP_KEY_SECRET="${APP_KEY_SECRET:-test/bells-sis/app-key}"

  if ! command -v aws >/dev/null 2>&1; then
    echo "aws CLI is required to sync APP_KEY from Secrets Manager." >&2
    exit 1
  fi

  { set +x; } 2>/dev/null
  app_key="$(aws secretsmanager get-secret-value --secret-id "$APP_KEY_SECRET" --region "$REGION" --query SecretString --output text | tr -d '\r')"
  app_key="${app_key%"${app_key##*[![:space:]]}"}"
  if [[ -z "$app_key" || "$app_key" == "None" ]]; then
    echo "App key secret $APP_KEY_SECRET is empty." >&2
    exit 1
  fi
  jq -n --arg key "$app_key" --arg secret "$APP_KEY_SECRET" \
    '{APP_KEY: $key, AWS_APP_KEY_SECRET: $secret}' \
    | python3 "$PATCH" "$ENV_FILE"
  chown www-data:www-data "$ENV_FILE" 2>/dev/null || true
  echo "Wrote quoted APP_KEY from $APP_KEY_SECRET into $ENV_FILE"
  unset app_key
fi

python3 "$PATCH" "$ENV_FILE" --normalize-app-key --check-app-key
