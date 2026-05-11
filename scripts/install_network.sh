#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${VPNWEB_BACKEND:-}" ]]; then
  if [[ -f /opt/vpnweb/.env ]]; then
    VPNWEB_BACKEND="$(sed -n 's/^VPN_BACKEND=//p' /opt/vpnweb/.env | head -n1 || true)"
  fi
fi
if [[ -z "${VPNWEB_BACKEND:-}" ]]; then
  echo "VPNWEB_BACKEND is required (or /opt/vpnweb/.env with VPN_BACKEND)" >&2
  exit 1
fi

apt-get install -y nftables iptables

VPN_IFACE=""
VPN_SUBNET_V4=""
VPN_SUBNET_V6=""
VPN_PORT=""

case "${VPNWEB_BACKEND}" in
  wireguard)
    VPN_IFACE="wg0"
    VPN_SUBNET_V4="10.66.66.0/24"
    VPN_SUBNET_V6="fd42:42:42::/64"
    VPN_PORT="51820"
    ;;
  openvpn)
    VPN_IFACE="tun0"
    VPN_SUBNET_V4="10.8.0.0/24"
    VPN_SUBNET_V6="fd43:43:43::/64"
    VPN_PORT="1194"
    ;;
  *)
    echo "Unsupported backend: ${VPNWEB_BACKEND}" >&2
    exit 2
    ;;
esac

WAN_IFACE="$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); exit}}')"
if [[ -z "${WAN_IFACE}" ]]; then
  WAN_IFACE="$(ip -o -4 route show to default | awk '{print $5; exit}')"
fi

if [[ -z "${WAN_IFACE}" ]]; then
  echo "Cannot detect WAN interface" >&2
  exit 3
fi

cat > /etc/sysctl.d/99-vpnweb.conf <<SYSCTL
net.ipv4.ip_forward=1
net.ipv6.conf.all.forwarding=1
SYSCTL
sysctl --system >/dev/null

has_global_v6="0"
if ip -6 addr show dev "${WAN_IFACE}" scope global 2>/dev/null | grep -q 'inet6 '; then
  has_global_v6="1"
fi

if command -v nft >/dev/null 2>&1; then
  FIREWALL_BACKEND="nftables"
  nft delete table inet vpnweb_filter >/dev/null 2>&1 || true
  nft delete table ip vpnweb_nat >/dev/null 2>&1 || true
  nft delete table ip6 vpnweb_nat6 >/dev/null 2>&1 || true
  mkdir -p /etc/nftables.d
  cat > /etc/nftables.d/vpnweb.nft <<NFT
table inet vpnweb_filter {
  chain input {
    type filter hook input priority 0;
    iifname "${WAN_IFACE}" udp dport ${VPN_PORT} accept
  }

  chain forward {
    type filter hook forward priority 0;
    iifname "${VPN_IFACE}" oifname "${WAN_IFACE}" accept
    iifname "${WAN_IFACE}" oifname "${VPN_IFACE}" ct state related,established accept
  }
}

table ip vpnweb_nat {
  chain postrouting {
    type nat hook postrouting priority 100;
    ip saddr ${VPN_SUBNET_V4} oifname "${WAN_IFACE}" masquerade
  }
}
NFT

  if [[ "${has_global_v6}" == "1" ]]; then
    cat >> /etc/nftables.d/vpnweb.nft <<NFT

table ip6 vpnweb_nat6 {
  chain postrouting {
    type nat hook postrouting priority 100;
    ip6 saddr ${VPN_SUBNET_V6} oifname "${WAN_IFACE}" masquerade
  }
}
NFT
  fi

  touch /etc/nftables.conf
  if ! grep -q 'include "/etc/nftables.d/*.nft"' /etc/nftables.conf; then
    cat >> /etc/nftables.conf <<CONF

include "/etc/nftables.d/*.nft"
CONF
  fi

  systemctl enable nftables >/dev/null || true
  nft -f /etc/nftables.conf
  systemctl restart nftables || true
else
  FIREWALL_BACKEND="iptables"
  iptables -C INPUT -i "${WAN_IFACE}" -p udp --dport "${VPN_PORT}" -j ACCEPT 2>/dev/null || iptables -I INPUT -i "${WAN_IFACE}" -p udp --dport "${VPN_PORT}" -j ACCEPT
  iptables -C FORWARD -i "${VPN_IFACE}" -o "${WAN_IFACE}" -j ACCEPT 2>/dev/null || iptables -I FORWARD -i "${VPN_IFACE}" -o "${WAN_IFACE}" -j ACCEPT
  iptables -C FORWARD -i "${WAN_IFACE}" -o "${VPN_IFACE}" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || iptables -I FORWARD -i "${WAN_IFACE}" -o "${VPN_IFACE}" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
  iptables -t nat -C POSTROUTING -s "${VPN_SUBNET_V4}" -o "${WAN_IFACE}" -j MASQUERADE 2>/dev/null || iptables -t nat -I POSTROUTING -s "${VPN_SUBNET_V4}" -o "${WAN_IFACE}" -j MASQUERADE

  if [[ "${has_global_v6}" == "1" ]]; then
    ip6tables -C FORWARD -i "${VPN_IFACE}" -o "${WAN_IFACE}" -j ACCEPT 2>/dev/null || ip6tables -I FORWARD -i "${VPN_IFACE}" -o "${WAN_IFACE}" -j ACCEPT
    ip6tables -C FORWARD -i "${WAN_IFACE}" -o "${VPN_IFACE}" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || ip6tables -I FORWARD -i "${WAN_IFACE}" -o "${VPN_IFACE}" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
    ip6tables -t nat -C POSTROUTING -s "${VPN_SUBNET_V6}" -o "${WAN_IFACE}" -j MASQUERADE 2>/dev/null || ip6tables -t nat -I POSTROUTING -s "${VPN_SUBNET_V6}" -o "${WAN_IFACE}" -j MASQUERADE
  fi
fi

cat > /etc/vpnweb-network-summary <<SUMMARY
VPNWEB_NETWORK_SUMMARY
backend=${VPNWEB_BACKEND}
wan_interface=${WAN_IFACE}
vpn_interface=${VPN_IFACE}
vpn_port_udp=${VPN_PORT}
vpn_subnet_ipv4=${VPN_SUBNET_V4}
vpn_subnet_ipv6=${VPN_SUBNET_V6}
ipv6_global_on_wan=${has_global_v6}
firewall_backend=${FIREWALL_BACKEND}
SUMMARY

echo "Network configured: WAN=${WAN_IFACE}, backend=${VPNWEB_BACKEND}, ipv6_global=${has_global_v6}"
