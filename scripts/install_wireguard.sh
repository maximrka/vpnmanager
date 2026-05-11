#!/usr/bin/env bash
set -euo pipefail

apt-get install -y wireguard wireguard-tools

has_global_v6="0"
if [[ -f /etc/vpnweb-network-summary ]]; then
  v="$(awk -F'=' '$1=="ipv6_global_on_wan"{print $2; exit}' /etc/vpnweb-network-summary || true)"
  if [[ "${v}" == "1" ]]; then
    has_global_v6="1"
  fi
fi

if [[ ! -f /etc/wireguard/wg0.conf ]]; then
  umask 077
  mkdir -p /etc/wireguard
  private_key="$(wg genkey)"
  public_key="$(printf '%s' "${private_key}" | wg pubkey)"
  iface_addr="10.66.66.1/24"
  if [[ "${has_global_v6}" == "1" ]]; then
    iface_addr="10.66.66.1/24,fd42:42:42::1/64"
  fi
  cat > /etc/wireguard/wg0.conf <<WG
[Interface]
Address = ${iface_addr}
ListenPort = 51820
PrivateKey = ${private_key}
# PublicKey = ${public_key}
WG
  chmod 600 /etc/wireguard/wg0.conf
fi

systemctl enable wg-quick@wg0 >/dev/null || true
systemctl restart wg-quick@wg0 || true
