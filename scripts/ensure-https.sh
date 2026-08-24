#!/usr/bin/env bash
# Idempotent nginx TLS for the AWS API host. No-op on VPS (no bells-sis-api site).
set -euo pipefail

SITE="/etc/nginx/sites-available/bells-sis-api"
if [[ ! -f "$SITE" ]]; then
  echo "No $SITE; skipping HTTPS ensure."
  exit 0
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH="$ROOT/scripts/patch-dotenv.py"
ENV_FILE="${ENV_FILE:-$ROOT/.env}"

fqdn_from_env() {
  local url
  url="$(python3 "$PATCH" "$ENV_FILE" --get APP_URL 2>/dev/null || true)"
  python3 -c 'import sys; from urllib.parse import urlparse
u=sys.argv[1].strip()
print(urlparse(u).hostname or "")' "${url:-}" 2>/dev/null || true
}

server_name_from_nginx() {
  awk '/server_name/ {print $2; exit}' "$SITE" | tr -d ';'
}

php_sock() {
  for s in /run/php/php8.4-fpm.sock /run/php/php8.3-fpm.sock; do
    if [[ -S "$s" ]]; then
      echo "$s"
      return
    fi
  done
  echo /run/php/php8.4-fpm.sock
}

FQDN="${API_FQDN:-$(fqdn_from_env)}"
FQDN="${FQDN:-$(server_name_from_nginx)}"
if [[ -z "$FQDN" || "$FQDN" == "_" ]]; then
  echo "Cannot determine API FQDN for HTTPS." >&2
  exit 1
fi

SOCK="$(php_sock)"
LE_LIVE="/etc/letsencrypt/live/$FQDN"
SELF_DIR=/etc/nginx/ssl/selfsigned
mkdir -p "$SELF_DIR"
if [[ ! -f "$SELF_DIR/fullchain.pem" || ! -f "$SELF_DIR/privkey.pem" ]]; then
  openssl req -x509 -nodes -newkey rsa:2048 -days 30 \
    -keyout "$SELF_DIR/privkey.pem" -out "$SELF_DIR/fullchain.pem" \
    -subj "/CN=$FQDN" >/dev/null 2>&1
fi

if [[ -f "$LE_LIVE/fullchain.pem" && -f "$LE_LIVE/privkey.pem" ]]; then
  CERT="$LE_LIVE/fullchain.pem"
  KEY="$LE_LIVE/privkey.pem"
else
  CERT="$SELF_DIR/fullchain.pem"
  KEY="$SELF_DIR/privkey.pem"
fi

install_vhost() {
  local cert="$1" key="$2"
  cat >"$SITE" <<NGX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $FQDN;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/api/public;
        default_type "text/plain";
        try_files \$uri =404;
    }
    location / {
        return 301 https://\$host\$request_uri;
    }
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $FQDN;
    root /var/www/api/public;
    index index.php index.html;
    client_max_body_size 16m;

    ssl_certificate     $cert;
    ssl_certificate_key $key;
    ssl_protocols TLSv1.2 TLSv1.3;

    location = /up {
        default_type text/plain;
        try_files /up =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGX
  ln -sfn "$SITE" /etc/nginx/sites-enabled/bells-sis-api
  nginx -t
  systemctl reload nginx
}

install_vhost "$CERT" "$KEY"

EMAIL="${LETSENCRYPT_EMAIL:-}"
if [[ -z "$EMAIL" && -f "$ENV_FILE" ]]; then
  EMAIL="$(python3 "$PATCH" "$ENV_FILE" --get LETSENCRYPT_EMAIL 2>/dev/null || true)"
fi
if [[ -z "$EMAIL" && -f /etc/letsencrypt/renewal/${FQDN}.conf ]]; then
  EMAIL="$(awk -F= '/^email/ {gsub(/ /,"",$2); print $2; exit}' "/etc/letsencrypt/renewal/${FQDN}.conf" || true)"
fi

if ! command -v certbot >/dev/null 2>&1; then
  echo "certbot not installed; HTTPS is using $CERT"
  exit 0
fi

CERTBOT_MAIL=(--register-unsafely-without-email)
if [[ -n "$EMAIL" ]]; then
  CERTBOT_MAIL=(-m "$EMAIL")
fi

if certbot certonly --nginx -d "$FQDN" \
  --non-interactive --agree-tos --keep-until-expiring \
  "${CERTBOT_MAIL[@]}"; then
  if [[ -f "$LE_LIVE/fullchain.pem" ]]; then
    install_vhost "$LE_LIVE/fullchain.pem" "$LE_LIVE/privkey.pem"
  fi
  echo "HTTPS ready for https://$FQDN"
else
  echo "certbot failed; nginx still serves HTTPS with $CERT" >&2
fi
