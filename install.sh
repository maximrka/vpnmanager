#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo ./install.sh"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="${ROOT_DIR}/scripts"

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

if [[ ! -f /etc/os-release ]]; then
  echo "Cannot detect OS (/etc/os-release not found)."
  exit 1
fi

# shellcheck source=/etc/os-release
source /etc/os-release
OS_ID="${ID:-unknown}"
OS_VER="${VERSION_ID:-unknown}"

is_supported=false
case "${OS_ID}" in
  ubuntu)
    if [[ "${OS_VER}" == "22.04" || "${OS_VER}" == "24.04" ]]; then
      is_supported=true
    fi
    ;;
  debian)
    if [[ "${OS_VER}" == "12" || "${OS_VER}" == "13" ]]; then
      is_supported=true
    fi
    ;;
esac

if [[ "${is_supported}" != "true" ]]; then
  echo "Unsupported OS: ${OS_ID} ${OS_VER}."
  echo "Supported: Debian 12/13, Ubuntu 22.04, Ubuntu 24.04"
  exit 1
fi

echo "Detected OS: ${OS_ID} ${OS_VER}"
echo "Choose VPN backend:"
echo "  1) WireGuard"
echo "  2) OpenVPN"
read -r -p "Enter choice [1-2]: " choice

BACKEND=""
case "${choice}" in
  1) BACKEND="wireguard" ;;
  2) BACKEND="openvpn" ;;
  *)
    echo "Invalid choice"
    exit 1
    ;;
esac

export VPNWEB_BACKEND="${BACKEND}"
export VPNWEB_ROOT_DIR="${ROOT_DIR}"
export VPNWEB_ADMIN_PASSWORD="$(generate_password)"

echo "[1/4] Installing common components..."
"${SCRIPTS_DIR}/install_common.sh"

echo "[2/4] Installing VPN backend (${BACKEND})..."
if [[ "${BACKEND}" == "wireguard" ]]; then
  "${SCRIPTS_DIR}/install_wireguard.sh"
else
  "${SCRIPTS_DIR}/install_openvpn.sh"
fi

echo "[3/4] Configuring forwarding and firewall..."
"${SCRIPTS_DIR}/install_network.sh"

echo "[4/4] Finalizing Apache and permissions..."
a2enmod rewrite >/dev/null
systemctl enable apache2 >/dev/null
systemctl restart apache2

PANEL_URL="http://$(hostname -I | awk '{print $1}')/"
echo ""
echo "Install complete"
echo "Panel URL: ${PANEL_URL}"
echo "Initial admin user: admin"
echo "Initial admin password: ${VPNWEB_ADMIN_PASSWORD}"
echo "Saved to: /root/vpnweb-initial-admin.txt"
if [[ -f /etc/vpnweb-network-summary ]]; then
  echo ""
  echo "Network summary:"
  cat /etc/vpnweb-network-summary
fi
echo "IMPORTANT: change admin password right after first login."

cat > /root/vpnweb-initial-admin.txt <<CREDS
VPN Web Panel initial credentials
Date: $(date -u +"%Y-%m-%d %H:%M:%S UTC")
Panel URL: ${PANEL_URL}
Username: admin
Password: ${VPNWEB_ADMIN_PASSWORD}
CREDS
chmod 600 /root/vpnweb-initial-admin.txt
