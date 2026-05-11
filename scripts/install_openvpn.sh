#!/usr/bin/env bash
set -euo pipefail

apt-get install -y openvpn easy-rsa

EASYRSA_DIR="/etc/openvpn/easy-rsa"
SERVER_DIR="/etc/openvpn/server"
CLIENTS_DIR="/etc/openvpn/clients"

mkdir -p "${SERVER_DIR}" "${CLIENTS_DIR}"

if [[ ! -d "${EASYRSA_DIR}" ]]; then
  make-cadir "${EASYRSA_DIR}"
fi

cd "${EASYRSA_DIR}"

if [[ ! -f vars ]]; then
  cat > vars <<VARS
set_var EASYRSA_ALGO ec
set_var EASYRSA_DIGEST sha256
set_var EASYRSA_BATCH "yes"
set_var EASYRSA_REQ_CN "vpnweb-ca"
VARS
fi

if [[ ! -d pki ]]; then
  ./easyrsa init-pki
fi

if [[ ! -f pki/ca.crt ]]; then
  ./easyrsa --batch build-ca nopass
fi

if [[ ! -f pki/issued/server.crt ]]; then
  ./easyrsa --batch build-server-full server nopass
fi

if [[ ! -f "${SERVER_DIR}/ta.key" ]]; then
  openvpn --genkey secret "${SERVER_DIR}/ta.key"
fi

if [[ ! -f pki/crl.pem ]]; then
  ./easyrsa gen-crl
fi

install -m 600 pki/private/server.key "${SERVER_DIR}/server.key"
install -m 644 pki/issued/server.crt "${SERVER_DIR}/server.crt"
install -m 644 pki/ca.crt "${SERVER_DIR}/ca.crt"
install -m 644 pki/crl.pem "${SERVER_DIR}/crl.pem"
touch "${SERVER_DIR}/disabled-clients.txt"
chmod 644 "${SERVER_DIR}/disabled-clients.txt"

cat > "${SERVER_DIR}/check-cn.sh" <<'CHK'
#!/usr/bin/env bash
set -euo pipefail

deny_file="${1:-/etc/openvpn/server/disabled-clients.txt}"
depth="${2:-}"
subject="${3:-}"

if [[ "${depth}" != "0" ]]; then
  exit 0
fi

cn=""
if [[ "${subject}" =~ /CN=([^/]+) ]]; then
  cn="${BASH_REMATCH[1]}"
elif [[ "${subject}" =~ CN=([^,]+) ]]; then
  cn="${BASH_REMATCH[1]}"
fi

if [[ -z "${cn}" ]]; then
  exit 1
fi

if [[ -f "${deny_file}" ]] && grep -Fxq "${cn}" "${deny_file}"; then
  exit 1
fi

exit 0
CHK
chmod 755 "${SERVER_DIR}/check-cn.sh"

cat > "${SERVER_DIR}/server.conf" <<CONF
port 1194
proto udp
dev tun
tun-ipv6
user nobody
group nogroup
persist-key
persist-tun
keepalive 10 120
topology subnet
server 10.8.0.0 255.255.255.0
server-ipv6 fd43:43:43::/64
ifconfig-pool-persist /var/log/openvpn/ipp.txt
status /var/log/openvpn/openvpn-status.log
verb 3
tls-version-min 1.2
ca ${SERVER_DIR}/ca.crt
cert ${SERVER_DIR}/server.crt
key ${SERVER_DIR}/server.key
crl-verify ${SERVER_DIR}/crl.pem
dh none
ecdh-curve prime256v1
tls-crypt ${SERVER_DIR}/ta.key
cipher AES-256-GCM
auth SHA256
explicit-exit-notify 1
push "redirect-gateway def1 bypass-dhcp"
push "redirect-gateway ipv6"
push "route-ipv6 2000::/3"
push "dhcp-option DNS 1.1.1.1"
push "dhcp-option DNS 8.8.8.8"
push "dhcp-option DNS6 2606:4700:4700::1111"
push "dhcp-option DNS6 2606:4700:4700::1001"
script-security 2
tls-verify "${SERVER_DIR}/check-cn.sh ${SERVER_DIR}/disabled-clients.txt"
CONF

systemctl enable openvpn-server@server >/dev/null || true
systemctl restart openvpn-server@server || true
