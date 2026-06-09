#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/opt/vpnweb"
VAR_DIR="${APP_ROOT}/var"
DB_PATH="${VAR_DIR}/vpnweb.sqlite"
QR_DIR="${VAR_DIR}/qr"
ENV_PATH="${APP_ROOT}/.env"
WG_CONF="/etc/wireguard/wg0.conf"
ADMIN_FILE="${VAR_DIR}/initial-admin.txt"

generate_password() {
  local len pass
  len=$(( (RANDOM % 6) + 10 ))
  while true; do
    pass="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9._!@#$%+-' | head -c "${len}")"
    if [[ "${#pass}" -ge 10 ]]; then
      echo "${pass}"
      return
    fi
  done
}

detect_endpoint_host() {
  if [[ -n "${WG_HOST:-}" ]]; then
    echo "${WG_HOST}"
    return
  fi

  local detected
  detected="$(curl -4fsS --max-time 5 https://api64.ipify.org 2>/dev/null || true)"
  if [[ -n "${detected}" ]]; then
    echo "${detected}"
    return
  fi

  local wan
  wan="$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); exit}}')"
  if [[ -n "${wan}" ]]; then
    detected="$(ip -o -4 addr show dev "${wan}" scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1)"
    if [[ -n "${detected}" ]]; then
      echo "${detected}"
      return
    fi
  fi

  echo "REPLACE_WITH_SERVER_IP"
}

detect_wan_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); exit}}'
}

ipv6_mode() {
  local mode="${WG_ENABLE_IPV6:-auto}"
  if [[ "${mode}" == "1" || "${mode}" == "true" || "${mode}" == "yes" ]]; then
    echo "1"
    return
  fi
  if [[ "${mode}" == "0" || "${mode}" == "false" || "${mode}" == "no" ]]; then
    echo "0"
    return
  fi

  local wan
  wan="$(detect_wan_iface)"
  if [[ -n "${wan}" ]] && ip -6 addr show dev "${wan}" scope global 2>/dev/null | grep -q 'inet6 '; then
    echo "1"
    return
  fi

  echo "0"
}

configure_apache() {
  local port="${APACHE_PORT:-8080}"
  sed -i "s/Listen 80/Listen ${port}/" /etc/apache2/ports.conf
  cat > /etc/apache2/sites-available/000-default.conf <<APACHE
<VirtualHost *:${port}>
    ServerAdmin webmaster@localhost
    DocumentRoot /opt/vpnweb/public

    <Directory /opt/vpnweb/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
APACHE
}

initialize_web() {
  local app_secret admin_password endpoint_host ipv6_enabled wg_port

  mkdir -p "${VAR_DIR}" "${QR_DIR}" /etc/wireguard/clients /opt/vpnweb/bin
  chown -R www-data:www-data "${VAR_DIR}"
  chmod 750 "${VAR_DIR}"

  if [[ -f "${ENV_PATH}" ]]; then
    app_secret="$(sed -n 's/^APP_SECRET=//p' "${ENV_PATH}" | head -n1 || true)"
  fi
  if [[ -z "${app_secret:-}" ]]; then
    app_secret="$(openssl rand -hex 32)"
  fi

  endpoint_host="$(detect_endpoint_host)"
  ipv6_enabled="$(ipv6_mode)"
  wg_port="${WG_PORT:-51820}"

  cat > "${ENV_PATH}" <<ENV
APP_ENV=production
APP_NAME=${APP_NAME:-VPN Web Panel}
APP_LOGO_TEXT=${APP_LOGO_TEXT:-VPNWEB}
VPN_BACKEND=wireguard
DB_PATH=${DB_PATH}
QR_DIR=${QR_DIR}
TOTP_ISSUER=${TOTP_ISSUER:-VPN Web Panel}
APP_SECRET=${app_secret}
VPNWEB_ENDPOINT_HOST=${endpoint_host}
WG_PORT=${wg_port}
WG_ENABLE_IPV6=${ipv6_enabled}
ENV

  if [[ ! -f "${DB_PATH}" ]]; then
    sqlite3 "${DB_PATH}" < "${APP_ROOT}/schema.sql"
  fi

  sqlite3 "${DB_PATH}" "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, key_value TEXT NOT NULL, created_at TEXT NOT NULL);"
  if ! sqlite3 "${DB_PATH}" "PRAGMA table_info(vpn_clients);" | awk -F'|' '$2=="assigned_ip" {found=1} END {exit found?0:1}'; then
    sqlite3 "${DB_PATH}" "ALTER TABLE vpn_clients ADD COLUMN assigned_ip TEXT;"
  fi

  chown www-data:www-data "${DB_PATH}"
  chmod 660 "${DB_PATH}"

  if [[ "$(sqlite3 "${DB_PATH}" "SELECT COUNT(*) FROM users WHERE username='admin';")" == "0" ]]; then
    admin_password="${ADMIN_PASSWORD:-$(generate_password)}"
    admin_hash="$(VPNWEB_ADMIN_PASSWORD="${admin_password}" php -r '$p=getenv("VPNWEB_ADMIN_PASSWORD"); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;')"
    sqlite3 "${DB_PATH}" "INSERT INTO users(username,password_hash,is_active,created_at,updated_at) VALUES ('admin','${admin_hash}',1,datetime('now'),datetime('now'));"
    cat > "${ADMIN_FILE}" <<CREDS
VPN Web Panel initial credentials
Username: admin
Password: ${admin_password}
CREDS
    chmod 600 "${ADMIN_FILE}"
    echo "Initial admin password stored at ${ADMIN_FILE}"
    echo "Initial admin password: ${admin_password}"
  fi

  cat > /etc/sudoers.d/vpnweb <<'SUDOERS'
www-data ALL=(root) NOPASSWD: /opt/vpnweb/bin/vpnctl
SUDOERS
  chmod 440 /etc/sudoers.d/vpnweb

  configure_apache
}

initialize_wireguard() {
  local private_key public_key iface_addr wan port ipv6_enabled

  wan="$(detect_wan_iface)"
  port="${WG_PORT:-51820}"
  ipv6_enabled="$(ipv6_mode)"

  sysctl -w net.ipv4.ip_forward=1 >/dev/null
  if [[ "${ipv6_enabled}" == "1" ]]; then
    sysctl -w net.ipv6.conf.all.forwarding=1 >/dev/null
    iface_addr="10.66.66.1/24,fd42:42:42::1/64"
  else
    iface_addr="10.66.66.1/24"
  fi

  if [[ ! -f "${WG_CONF}" ]]; then
    umask 077
    mkdir -p /etc/wireguard
    private_key="$(wg genkey)"
    public_key="$(printf '%s' "${private_key}" | wg pubkey)"
    cat > "${WG_CONF}" <<WG
[Interface]
Address = ${iface_addr}
ListenPort = ${port}
PrivateKey = ${private_key}
PostUp = iptables -A FORWARD -i ${wan} -o wg0 -j ACCEPT; iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${wan} -j MASQUERADE
PostDown = iptables -D FORWARD -i ${wan} -o wg0 -j ACCEPT; iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${wan} -j MASQUERADE
# PublicKey = ${public_key}
WG
    if [[ "${ipv6_enabled}" == "1" ]]; then
      cat >> "${WG_CONF}" <<WG
PostUp = ip6tables -A FORWARD -i wg0 -j ACCEPT; ip6tables -t nat -A POSTROUTING -o ${wan} -j MASQUERADE
PostDown = ip6tables -D FORWARD -i wg0 -j ACCEPT; ip6tables -t nat -D POSTROUTING -o ${wan} -j MASQUERADE
WG
    fi
    chmod 600 "${WG_CONF}"
  fi

  if ip link show wg0 >/dev/null 2>&1; then
    wg-quick down wg0 || true
  fi
  wg-quick up wg0
}

cleanup() {
  wg-quick down wg0 >/dev/null 2>&1 || true
  if [[ -n "${APACHE_PID:-}" ]]; then
    kill "${APACHE_PID}" >/dev/null 2>&1 || true
    wait "${APACHE_PID}" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT INT TERM

initialize_web
initialize_wireguard

apache2ctl -D FOREGROUND &
APACHE_PID=$!
wait "${APACHE_PID}"
