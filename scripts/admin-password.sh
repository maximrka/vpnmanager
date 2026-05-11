#!/usr/bin/env bash
set -euo pipefail

DB_PATH="/opt/vpnweb/var/vpnweb.sqlite"
USER_NAME="admin"

if [[ ! -f "${DB_PATH}" ]]; then
  echo "Database not found: ${DB_PATH}" >&2
  exit 1
fi

generate_password() {
  local len pass
  len=$(( (RANDOM % 6) + 10 )) # 10..15
  while true; do
    pass="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9._!@#$%+-' | head -c "${len}")"
    if [[ "${#pass}" -ge 10 ]]; then
      echo "${pass}"
      return
    fi
  done
}

NEW_PASS="${1:-}"
if [[ -z "${NEW_PASS}" ]]; then
  NEW_PASS="$(generate_password)"
fi

PASS_HASH="$(VPNWEB_NEW_PASS="${NEW_PASS}" php -r 'echo password_hash(getenv("VPNWEB_NEW_PASS"), PASSWORD_DEFAULT), PHP_EOL;')"
sqlite3 "${DB_PATH}" "UPDATE users SET password_hash='${PASS_HASH}', updated_at=datetime('now') WHERE username='${USER_NAME}';"

updated="$(sqlite3 "${DB_PATH}" "SELECT changes();")"
if [[ "${updated}" != "1" ]]; then
  echo "Admin user not found in DB" >&2
  exit 2
fi

cat <<OUT
Password updated for user: ${USER_NAME}
New password: ${NEW_PASS}
OUT
