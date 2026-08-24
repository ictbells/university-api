#!/usr/bin/env bash
# Download .env from S3 when LOAD_ENV_FROM_S3=true.
# Uses AWS CLI credentials from the environment or instance role — not from .env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${ENV_DEST:-$ROOT/.env}"
ENABLED="${LOAD_ENV_FROM_S3:-false}"
FORCE="${ENV_S3_FORCE:-false}"

is_true() {
  case "${1,,}" in
    1|true|yes|on) return 0 ;;
    *) return 1 ;;
  esac
}

if ! is_true "$ENABLED"; then
  echo "LOAD_ENV_FROM_S3 is not true; leaving $DEST unchanged."
  exit 0
fi

BUCKET="${ENV_S3_BUCKET:-${AWS_BUCKET:-}}"
KEY="${ENV_S3_KEY:-api/.env}"
REGION="${AWS_DEFAULT_REGION:-us-east-1}"

if [[ -z "$BUCKET" || -z "$KEY" ]]; then
  echo "ENV_S3_BUCKET (or AWS_BUCKET) and ENV_S3_KEY are required." >&2
  exit 1
fi

if [[ -f "$DEST" ]] && ! is_true "$FORCE"; then
  echo "$DEST already exists. Set ENV_S3_FORCE=true to overwrite." >&2
  exit 1
fi

URI="s3://${BUCKET}/${KEY#/}"
echo "Pulling env from ${URI}"
aws s3 cp "$URI" "$DEST" --region "$REGION" --only-show-errors
echo "Wrote $DEST"
