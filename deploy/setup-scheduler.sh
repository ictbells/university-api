#!/usr/bin/env bash
# Install (or verify) the Bells SIS Laravel scheduler as a systemd timer.
# Idempotent: exits successfully if the timer is already enabled.
#
# Usage (from repo root on Linux):
#   sudo ./deploy/setup-scheduler.sh
#
# Optional environment overrides:
#   API_DIR=/var/www/office/api
#   PHP_BIN=/usr/bin/php
#   SCHEDULER_USER=www-data
#   SCHEDULER_GROUP=www-data
#   UNIT_NAME=bells-sis-scheduler

set -euo pipefail

UNIT_NAME="${UNIT_NAME:-bells-sis-scheduler}"
SERVICE_FILE="/etc/systemd/system/${UNIT_NAME}.service"
TIMER_FILE="/etc/systemd/system/${UNIT_NAME}.timer"
ENV_FILE="/etc/default/${UNIT_NAME}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATE_DIR="${SCRIPT_DIR}/systemd"

API_DIR="${API_DIR:-${REPO_ROOT}/api}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
SCHEDULER_USER="${SCHEDULER_USER:-www-data}"
SCHEDULER_GROUP="${SCHEDULER_GROUP:-${SCHEDULER_USER}}"

log() { printf '[setup-scheduler] %s\n' "$*"; }
fail() { printf '[setup-scheduler] ERROR: %s\n' "$*" >&2; exit 1; }

if [[ "$(uname -s)" != "Linux" ]]; then
  log "Not Linux — skipping systemd scheduler setup."
  exit 0
fi

if ! command -v systemctl >/dev/null 2>&1; then
  log "systemd not found — skipping scheduler setup."
  exit 0
fi

if [[ -f "${ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
  API_DIR="${API_DIR:-${REPO_ROOT}/api}"
  PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
  SCHEDULER_USER="${SCHEDULER_USER:-www-data}"
  SCHEDULER_GROUP="${SCHEDULER_GROUP:-${SCHEDULER_USER}}"
fi

[[ -d "${API_DIR}" ]] || fail "API directory not found: ${API_DIR}"
[[ -f "${API_DIR}/artisan" ]] || fail "artisan not found in ${API_DIR}"
[[ -n "${PHP_BIN}" && -x "${PHP_BIN}" ]] || fail "PHP binary not found. Set PHP_BIN."

if ! id "${SCHEDULER_USER}" >/dev/null 2>&1; then
  if id apache >/dev/null 2>&1; then
    SCHEDULER_USER=apache
    SCHEDULER_GROUP=apache
    log "User www-data not found; using apache."
  else
    fail "Scheduler user '${SCHEDULER_USER}' does not exist. Set SCHEDULER_USER."
  fi
fi

if systemctl is-enabled "${UNIT_NAME}.timer" >/dev/null 2>&1; then
  log "Timer ${UNIT_NAME}.timer is already enabled."
  systemctl is-active "${UNIT_NAME}.timer" >/dev/null 2>&1 \
    && log "Timer is active." \
    || log "Timer is enabled but not active — run: sudo systemctl start ${UNIT_NAME}.timer"
  exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
  fail "Root required to install systemd units. Re-run with sudo."
fi

[[ -f "${TEMPLATE_DIR}/${UNIT_NAME}.service" ]] || fail "Missing template ${TEMPLATE_DIR}/${UNIT_NAME}.service"
[[ -f "${TEMPLATE_DIR}/${UNIT_NAME}.timer" ]] || fail "Missing template ${TEMPLATE_DIR}/${UNIT_NAME}.timer"

render_unit() {
  local template="$1"
  local dest="$2"
  sed \
    -e "s|@API_DIR@|${API_DIR}|g" \
    -e "s|@PHP_BIN@|${PHP_BIN}|g" \
    -e "s|@SCHEDULER_USER@|${SCHEDULER_USER}|g" \
    -e "s|@SCHEDULER_GROUP@|${SCHEDULER_GROUP}|g" \
    "${template}" > "${dest}"
  chmod 644 "${dest}"
}

log "Installing ${UNIT_NAME} systemd units..."
log "  API_DIR=${API_DIR}"
log "  PHP_BIN=${PHP_BIN}"
log "  SCHEDULER_USER=${SCHEDULER_USER}"

render_unit "${TEMPLATE_DIR}/${UNIT_NAME}.service" "${SERVICE_FILE}"
cp "${TEMPLATE_DIR}/${UNIT_NAME}.timer" "${TIMER_FILE}"
chmod 644 "${TIMER_FILE}"

if [[ ! -f "${ENV_FILE}" ]]; then
  cat > "${ENV_FILE}" <<EOF
# Bells SIS scheduler overrides (optional)
API_DIR=${API_DIR}
PHP_BIN=${PHP_BIN}
SCHEDULER_USER=${SCHEDULER_USER}
SCHEDULER_GROUP=${SCHEDULER_GROUP}
EOF
  chmod 644 "${ENV_FILE}"
  log "Wrote defaults to ${ENV_FILE}"
fi

# Ensure Laravel can write logs and cache for scheduled tasks.
if id "${SCHEDULER_USER}" >/dev/null 2>&1; then
  install -d -o "${SCHEDULER_USER}" -g "${SCHEDULER_GROUP}" -m 775 "${API_DIR}/storage/logs" 2>/dev/null || true
fi

systemctl daemon-reload
systemctl enable "${UNIT_NAME}.timer"
systemctl start "${UNIT_NAME}.timer"

log "Enabled and started ${UNIT_NAME}.timer"
systemctl status "${UNIT_NAME}.timer" --no-pager -l || true
log "Next runs: systemctl list-timers ${UNIT_NAME}.timer"
