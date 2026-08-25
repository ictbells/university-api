#!/usr/bin/env bash
# Download an env overlay from S3 and merge it into the server .env.
# Uses AWS CLI credentials from the environment or instance role.
# Secrets Manager keys (APP_KEY / DB_* / AWS_* secret names) stay on the host.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${ENV_DEST:-$ROOT/.env}"
PATCH="$ROOT/scripts/patch-dotenv.py"
ENABLED="${LOAD_ENV_FROM_S3:-}"

is_true() {
  case "${1,,}" in
    1|true|yes|on) return 0 ;;
    *) return 1 ;;
  esac
}

env_get() {
  python3 "$PATCH" "$DEST" --get "$1" 2>/dev/null || true
}

if [[ -z "$ENABLED" && -f "$DEST" ]]; then
  ENABLED="$(env_get LOAD_ENV_FROM_S3)"
fi
ENABLED="${ENABLED:-false}"

if ! is_true "$ENABLED"; then
  echo "LOAD_ENV_FROM_S3 is not true; leaving $DEST unchanged."
  exit 0
fi

BUCKET="${ENV_S3_BUCKET:-${AWS_BUCKET:-}}"
KEY="${ENV_S3_KEY:-}"
REGION="${AWS_DEFAULT_REGION:-${AWS_REGION:-}}"

if [[ -z "$BUCKET" && -f "$DEST" ]]; then
  BUCKET="$(env_get ENV_S3_BUCKET)"
  if [[ -z "$BUCKET" ]]; then
    BUCKET="$(env_get AWS_BUCKET)"
  fi
fi
if [[ -z "$KEY" && -f "$DEST" ]]; then
  KEY="$(env_get ENV_S3_KEY)"
fi
if [[ -z "$REGION" && -f "$DEST" ]]; then
  REGION="$(env_get AWS_DEFAULT_REGION)"
fi

KEY="${KEY:-api/.env}"
REGION="${REGION:-us-east-1}"

if [[ -z "$BUCKET" || -z "$KEY" ]]; then
  echo "ENV_S3_BUCKET (or AWS_BUCKET) and ENV_S3_KEY are required to pull env from S3." >&2
  exit 1
fi

URI="s3://${BUCKET}/${KEY#/}"
echo "Pulling env overlay from ${URI} (region=${REGION})"
OVERLAY="$(mktemp)"
cleanup() { rm -f "$OVERLAY"; }
trap cleanup EXIT

if ! aws s3 cp "$URI" "$OVERLAY" --region "$REGION" --only-show-errors; then
  echo "FATAL: could not download ${URI}. Upload PREMBLY_* / PAYSTACK_* to that object." >&2
  exit 1
fi

if [[ -f "$DEST" ]]; then
  python3 "$PATCH" "$DEST" --merge-from "$OVERLAY" --keep-infra
  echo "Merged overlay into $DEST"
else
  cp "$OVERLAY" "$DEST"
  echo "Wrote $DEST from ${URI}"
fi

chown www-data:www-data "$DEST" 2>/dev/null || true
chmod 640 "$DEST"
echo "Env overlay applied from ${URI}"
