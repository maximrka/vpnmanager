#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo ./update.sh"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="/opt/vpnweb"
WEB_ROOT="/var/www/html"
DB_PATH="${APP_ROOT}/var/vpnweb.sqlite"

if [[ ! -d "${APP_ROOT}" ]]; then
  echo "Existing install not found at ${APP_ROOT}. Run sudo ./install.sh first."
  exit 2
fi

apt-get update
apt-get install -y rsync sqlite3 apache2

# Update web code, but keep runtime files and server env.
rsync -a --delete \
  --exclude '.env' \
  --exclude 'var/' \
  "${ROOT_DIR}/web/" "${APP_ROOT}/"

# Ensure runtime dirs exist and permissions are correct.
mkdir -p "${APP_ROOT}/var/qr"
chown -R www-data:www-data "${APP_ROOT}/var"
chmod 750 "${APP_ROOT}/var"

if [[ -f "${DB_PATH}" ]]; then
  chown www-data:www-data "${DB_PATH}"
  chmod 660 "${DB_PATH}"

  has_assigned_ip="$(sqlite3 "${DB_PATH}" "PRAGMA table_info(vpn_clients);" | awk -F'|' '$2=="assigned_ip" {print 1}')"
  if [[ -z "${has_assigned_ip}" ]]; then
    sqlite3 "${DB_PATH}" "ALTER TABLE vpn_clients ADD COLUMN assigned_ip TEXT;"
  fi

  sqlite3 "${DB_PATH}" "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, key_value TEXT NOT NULL, created_at TEXT NOT NULL);"
fi

# Refresh privileged helper scripts.
mkdir -p /opt/vpnweb/bin
cp "${ROOT_DIR}/scripts/sudo-actions.sh" /opt/vpnweb/bin/vpnctl
chmod 750 /opt/vpnweb/bin/vpnctl
chown root:root /opt/vpnweb/bin/vpnctl

cp "${ROOT_DIR}/scripts/admin-password.sh" /opt/vpnweb/bin/vpnweb-admin-passwd
chmod 750 /opt/vpnweb/bin/vpnweb-admin-passwd
chown root:root /opt/vpnweb/bin/vpnweb-admin-passwd

# Re-apply network config (important when IPv6 appears later).
backend="$(sed -n 's/^VPN_BACKEND=//p' /opt/vpnweb/.env | head -n1 || true)"
if [[ -n "${backend}" ]]; then
  VPNWEB_BACKEND="${backend}" "${ROOT_DIR}/scripts/install_network.sh" || true
fi

# OpenVPN tls-verify runtime permissions (needed for user nobody).
if [[ -f /etc/openvpn/server/check-cn.sh ]]; then
  chmod 755 /etc/openvpn/server/check-cn.sh
fi
if [[ -f /etc/openvpn/server/disabled-clients.txt ]]; then
  chmod 644 /etc/openvpn/server/disabled-clients.txt
fi

cat > /etc/sudoers.d/vpnweb <<'SUDOERS'
www-data ALL=(root) NOPASSWD: /opt/vpnweb/bin/vpnctl
SUDOERS
chmod 440 /etc/sudoers.d/vpnweb

ln -sfn "${APP_ROOT}/public" "${WEB_ROOT}/vpnweb"
a2enmod rewrite >/dev/null || true
systemctl restart apache2

echo "Update complete"
if [[ -f /etc/vpnweb-network-summary ]]; then
  echo "Network summary:"
  cat /etc/vpnweb-network-summary
fi
