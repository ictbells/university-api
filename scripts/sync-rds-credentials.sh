#!/usr/bin/env bash
# On EC2: rewrite DB_* in .env from Secrets Manager (quoted) and GRANT the app user.
# Skips on VPS / hosts without IMDS so production SSH deploys keep their local .env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH="$ROOT/scripts/patch-dotenv.py"
ENV_FILE="${ENV_FILE:-$ROOT/.env}"
REGION="${AWS_REGION:-${AWS_DEFAULT_REGION:-}}"
APP_SECRET="${AWS_RDS_APP_SECRET:-}"
MASTER_SECRET="${AWS_RDS_MASTER_SECRET:-}"

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

if [[ "${SYNC_RDS_CREDENTIALS:-auto}" =~ ^(0|false|no|off)$ ]]; then
  echo "SYNC_RDS_CREDENTIALS disabled; leaving ${ENV_FILE} unchanged."
  exit 0
fi

if [[ ! "${SYNC_RDS_CREDENTIALS:-auto}" =~ ^(1|true|yes|on)$ ]]; then
  if ! is_ec2; then
    echo "Not an EC2 host; skipping RDS credential sync."
    exit 0
  fi
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

if [[ -z "$REGION" ]]; then
  REGION="$(env_get AWS_DEFAULT_REGION || true)"
fi
if [[ -z "$REGION" ]]; then
  REGION="$(imds_region || true)"
fi
REGION="${REGION:-us-east-1}"

if [[ -z "$APP_SECRET" ]]; then
  APP_SECRET="$(env_get AWS_RDS_APP_SECRET || true)"
fi
APP_SECRET="${APP_SECRET:-test/bells-sis/rds/app}"

if [[ -z "$MASTER_SECRET" ]]; then
  MASTER_SECRET="$(env_get AWS_RDS_MASTER_SECRET || true)"
fi
MASTER_SECRET="${MASTER_SECRET:-prod/bankease/rds/master}"

if ! command -v aws >/dev/null 2>&1 || ! command -v jq >/dev/null 2>&1; then
  echo "aws and jq are required to sync RDS credentials." >&2
  exit 1
fi

{ set +x; } 2>/dev/null
app_json="$(aws secretsmanager get-secret-value --secret-id "$APP_SECRET" --region "$REGION" --query SecretString --output text)"
app_user="$(echo "$app_json" | jq -r '.username // .user // empty')"
app_pass="$(echo "$app_json" | jq -r '.password // empty')"
db_host="$(echo "$app_json" | jq -r '.host // empty')"
db_port="$(echo "$app_json" | jq -r '.port // empty')"
db_name="$(echo "$app_json" | jq -r '.dbname // .database // empty')"
if [[ -z "$app_user" || -z "$app_pass" ]]; then
  echo "App secret $APP_SECRET is missing username/password." >&2
  exit 1
fi

jq -n \
  --arg host "$db_host" \
  --arg port "$db_port" \
  --arg name "$db_name" \
  --arg user "$app_user" \
  --arg pass "$app_pass" \
  --arg region "$REGION" \
  --arg appsec "$APP_SECRET" \
  --arg master "$MASTER_SECRET" \
  '{
    DB_CONNECTION: "mysql",
    DB_USERNAME: $user,
    DB_PASSWORD: $pass,
    AWS_DEFAULT_REGION: $region,
    AWS_RDS_APP_SECRET: $appsec,
    AWS_RDS_MASTER_SECRET: $master
  }
  + (if $host == "" then {} else {DB_HOST: $host} end)
  + (if $port == "" then {} else {DB_PORT: $port} end)
  + (if $name == "" then {} else {DB_DATABASE: $name} end)' \
  | python3 "$PATCH" "$ENV_FILE"

chown www-data:www-data "$ENV_FILE" 2>/dev/null || true
echo "Wrote quoted DB credentials from $APP_SECRET into $ENV_FILE"

grant_app_user() {
  if [[ -z "$MASTER_SECRET" ]]; then
    echo "No master secret; skipping CREATE USER / GRANT"
    return 0
  fi
  if ! command -v mysql >/dev/null 2>&1; then
    echo "mysql client missing; skipping GRANT (install mysql-client)" >&2
    return 0
  fi
  local master_json admin_user admin_pass host port name app_pass_sql
  master_json="$(aws secretsmanager get-secret-value --secret-id "$MASTER_SECRET" --region "$REGION" --query SecretString --output text)" || {
    echo "WARNING: cannot read master secret $MASTER_SECRET; skip GRANT" >&2
    return 0
  }
  admin_user="$(echo "$master_json" | jq -r '.username // .user // empty')"
  admin_pass="$(echo "$master_json" | jq -r '.password // empty')"
  host="$(env_get DB_HOST)"
  port="$(env_get DB_PORT)"
  name="$(env_get DB_DATABASE)"
  host="${host:-$db_host}"
  port="${port:-${db_port:-3306}}"
  name="${name:-$db_name}"
  if [[ -z "$admin_user" || -z "$admin_pass" ]]; then
    echo "WARNING: master secret missing username/password; skip GRANT" >&2
    return 0
  fi
  if [[ -z "$host" || -z "$name" ]]; then
    echo "WARNING: DB host/name unknown; skip GRANT" >&2
    return 0
  fi
  if [[ "$name" = "bankease" ]]; then
    echo "FATAL: refusing to GRANT on bankease schema" >&2
    return 1
  fi
  if [[ ! "$app_user" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "FATAL: refusing unsafe DB username" >&2
    return 1
  fi
  app_pass_sql="$(jq -n --arg p "$app_pass" '$p')"
  export MYSQL_PWD="$admin_pass"
  mysql --connect-timeout=10 -h "$host" -P "$port" -u "$admin_user" \
    -e "CREATE DATABASE IF NOT EXISTS \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql --connect-timeout=10 -h "$host" -P "$port" -u "$admin_user" \
    -e "CREATE USER IF NOT EXISTS \`$app_user\`@'%' IDENTIFIED BY $app_pass_sql; ALTER USER \`$app_user\`@'%' IDENTIFIED BY $app_pass_sql; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, LOCK TABLES, REFERENCES ON \`$name\`.* TO \`$app_user\`@'%'; FLUSH PRIVILEGES;"
  unset MYSQL_PWD
  echo "Granted $app_user on $name@$host"
}

grant_app_user
unset app_json app_pass
