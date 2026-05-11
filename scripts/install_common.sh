#!/usr/bin/env bash
set -euo pipefail

: "${VPNWEB_BACKEND:?VPNWEB_BACKEND is required}"
: "${VPNWEB_ROOT_DIR:?VPNWEB_ROOT_DIR is required}"
VPNWEB_ADMIN_PASSWORD="${VPNWEB_ADMIN_PASSWORD:-admin123}"

APP_ROOT="/opt/vpnweb"
WEB_ROOT="/var/www/html"
DB_PATH="${APP_ROOT}/var/vpnweb.sqlite"
QR_DIR="${APP_ROOT}/var/qr"
APP_SECRET=""

apt-get update
apt-get install -y apache2 php php-sqlite3 php-mbstring php-xml php-curl libapache2-mod-php sqlite3 qrencode openssl rsync

mkdir -p "${APP_ROOT}"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete "${VPNWEB_ROOT_DIR}/web/" "${APP_ROOT}/"
else
  rm -rf "${APP_ROOT:?}/"*
  cp -a "${VPNWEB_ROOT_DIR}/web/." "${APP_ROOT}/"
fi
mkdir -p "${APP_ROOT}/var" "${QR_DIR}"
chown -R www-data:www-data "${APP_ROOT}/var"
chmod -R 750 "${APP_ROOT}/var"

if [[ -f "${APP_ROOT}/.env" ]]; then
  APP_SECRET="$(sed -n 's/^APP_SECRET=//p' "${APP_ROOT}/.env" | head -n1 || true)"
fi
if [[ -z "${APP_SECRET}" ]]; then
  APP_SECRET="$(openssl rand -hex 32)"
fi

cat > "${APP_ROOT}/.env" <<ENV
APP_ENV=production
APP_NAME=VPN Web Panel
APP_LOGO_TEXT=VPNWEB
VPN_BACKEND=${VPNWEB_BACKEND}
DB_PATH=${DB_PATH}
QR_DIR=${QR_DIR}
TOTP_ISSUER=VPN Web Panel
APP_SECRET=${APP_SECRET}
ENV

if [[ ! -f "${DB_PATH}" ]]; then
  sqlite3 "${DB_PATH}" < "${APP_ROOT}/schema.sql"
fi

# Ensure web user can write SQLite DB and journal files.
chown www-data:www-data "${DB_PATH}"
chmod 660 "${DB_PATH}"
chown -R www-data:www-data "${APP_ROOT}/var"
chmod 750 "${APP_ROOT}/var"

has_assigned_ip="$(sqlite3 "${DB_PATH}" "PRAGMA table_info(vpn_clients);" | awk -F'|' '$2=="assigned_ip" {print 1}')"
if [[ -z "${has_assigned_ip}" ]]; then
  sqlite3 "${DB_PATH}" "ALTER TABLE vpn_clients ADD COLUMN assigned_ip TEXT;"
fi

sqlite3 "${DB_PATH}" "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, key_value TEXT NOT NULL, created_at TEXT NOT NULL);"

admin_exists="$(sqlite3 "${DB_PATH}" "SELECT COUNT(*) FROM users WHERE username='admin';")"
if [[ "${admin_exists}" == "0" ]]; then
  admin_hash="$(php -r '$p=getenv("VPNWEB_ADMIN_PASSWORD") ?: "admin123"; echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;')"
  sqlite3 "${DB_PATH}" "INSERT INTO users(username,password_hash,is_active,created_at,updated_at) VALUES ('admin','${admin_hash}',1,datetime('now'),datetime('now'));"
fi

ln -sfn "${APP_ROOT}/public" "${WEB_ROOT}/vpnweb"
cat > /etc/apache2/sites-available/000-default.conf <<'APACHE'
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/vpnweb

    <Directory /var/www/html/vpnweb>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
APACHE

cat > /etc/sudoers.d/vpnweb <<'SUDOERS'
www-data ALL=(root) NOPASSWD: /opt/vpnweb/bin/vpnctl
SUDOERS
chmod 440 /etc/sudoers.d/vpnweb

mkdir -p /opt/vpnweb/bin
cp "${VPNWEB_ROOT_DIR}/scripts/sudo-actions.sh" /opt/vpnweb/bin/vpnctl
chmod 750 /opt/vpnweb/bin/vpnctl
chown root:root /opt/vpnweb/bin/vpnctl
cp "${VPNWEB_ROOT_DIR}/scripts/admin-password.sh" /opt/vpnweb/bin/vpnweb-admin-passwd
chmod 750 /opt/vpnweb/bin/vpnweb-admin-passwd
chown root:root /opt/vpnweb/bin/vpnweb-admin-passwd
