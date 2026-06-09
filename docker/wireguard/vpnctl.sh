#!/usr/bin/env bash
set -euo pipefail

ENV_PATH="/opt/vpnweb/.env"
WG_CONF="/etc/wireguard/wg0.conf"
CLIENTS_DIR="/etc/wireguard/clients"

usage() {
  echo "Usage: $0 <status|start|stop|restart|create-client|delete-client|disable-client|enable-client|get-config> <wireguard> [name]"
  exit 1
}

if [[ $# -lt 2 ]]; then
  usage
fi

cmd="$1"
backend="$2"
arg3="${3:-}"

if [[ "${backend}" != "wireguard" ]]; then
  echo "invalid backend"
  exit 2
fi

config_get() {
  local key="$1"
  [[ -f "${ENV_PATH}" ]] || return 0
  sed -n "s/^${key}=//p" "${ENV_PATH}" | head -n1
}

detect_endpoint_host() {
  local host
  host="$(config_get VPNWEB_ENDPOINT_HOST)"
  if [[ -n "${host}" ]]; then
    echo "${host}"
    return
  fi

  local wan
  wan="$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); exit}}')"
  if [[ -n "${wan}" ]]; then
    ip -o -4 addr show dev "${wan}" scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1
    return
  fi

  echo "REPLACE_WITH_SERVER_IP"
}

wg_server_pubkey() {
  local pub
  pub="$(wg show wg0 public-key 2>/dev/null || true)"
  if [[ -n "${pub}" ]]; then
    printf '%s' "${pub}"
    return
  fi

  local priv
  priv="$(awk -F' = ' '/^PrivateKey = /{print $2; exit}' "${WG_CONF}")"
  printf '%s' "${priv}" | wg pubkey
}

wg_next_ip() {
  local used
  used="$(grep -RhoE '^Address = 10\.66\.66\.[0-9]+/32(,.*)?$' "${CLIENTS_DIR}" 2>/dev/null | sed -E 's/^Address = 10\.66\.66\.([0-9]+)\/32(,.*)?$/\1/' || true)"
  for i in $(seq 2 254); do
    if ! grep -qx "${i}" <<<"${used}"; then
      echo "10.66.66.${i}/32"
      return
    fi
  done
  echo "no free ip" >&2
  exit 3
}

wg_ipv6_enabled() {
  local mode
  mode="$(config_get WG_ENABLE_IPV6)"
  if [[ "${mode}" == "1" || "${mode}" == "true" || "${mode}" == "yes" ]]; then
    return 0
  fi
  if [[ "${mode}" == "0" || "${mode}" == "false" || "${mode}" == "no" ]]; then
    return 1
  fi
  grep -q 'fd42:42:42::1/64' "${WG_CONF}"
}

wg_ipv6_for_v4() {
  local addr4="$1"
  local host
  host="${addr4#10.66.66.}"
  host="${host%/32}"
  if [[ ! "${host}" =~ ^[0-9]+$ ]]; then
    return 1
  fi
  echo "fd42:42:42::${host}/128"
}

wg_sanitize_name() {
  local name="$1"
  [[ "${name}" =~ ^[a-zA-Z0-9_-]{3,32}$ ]]
}

wg_apply() {
  wg syncconf wg0 <(wg-quick strip wg0)
}

wg_create_client() {
  local name="$1"
  wg_sanitize_name "${name}" || { echo "bad name" >&2; exit 4; }

  mkdir -p "${CLIENTS_DIR}"
  local client_conf="${CLIENTS_DIR}/${name}.conf"
  if [[ -f "${client_conf}" ]]; then
    echo "exists" >&2
    exit 5
  fi

  local c_priv c_pub psk addr addr6 server_pub endpoint_host peer_allowed iface_addr wg_port
  c_priv="$(wg genkey)"
  c_pub="$(printf '%s' "${c_priv}" | wg pubkey)"
  psk="$(wg genpsk)"
  addr="$(wg_next_ip)"
  addr6=""
  if wg_ipv6_enabled; then
    addr6="$(wg_ipv6_for_v4 "${addr}" || true)"
  fi
  server_pub="$(wg_server_pubkey)"
  endpoint_host="$(detect_endpoint_host)"
  wg_port="$(config_get WG_PORT)"
  if [[ -z "${wg_port}" ]]; then
    wg_port="51820"
  fi
  iface_addr="${addr}"
  peer_allowed="${addr}"
  if [[ -n "${addr6}" ]]; then
    iface_addr="${addr},${addr6}"
    peer_allowed="${addr},${addr6}"
  fi

  cat > "${client_conf}" <<CFG
# TunnelName: ${name}
[Interface]
PrivateKey = ${c_priv}
Address = ${iface_addr}
DNS = 1.1.1.1

[Peer]
PublicKey = ${server_pub}
PresharedKey = ${psk}
Endpoint = ${endpoint_host}:${wg_port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CFG
  chmod 600 "${client_conf}"

  cat >> "${WG_CONF}" <<PEER

# vpnweb:${name}
[Peer]
PublicKey = ${c_pub}
PresharedKey = ${psk}
AllowedIPs = ${peer_allowed}
PEER

  wg_apply
  printf 'OK|%s|%s\n' "${name}" "${iface_addr}"
}

wg_delete_client() {
  local name="$1"
  wg_sanitize_name "${name}" || { echo "bad name" >&2; exit 4; }
  local client_conf="${CLIENTS_DIR}/${name}.conf"

  if [[ ! -f "${client_conf}" ]]; then
    echo "missing" >&2
    exit 6
  fi

  awk -v marker="# vpnweb:${name}" '
    $0 == marker {skip=1; saw_peer=0; next}
    skip && /^\[Peer\]$/ {saw_peer=1; next}
    skip && saw_peer && NF==0 {skip=0; saw_peer=0; next}
    skip {next}
    {print}
  ' "${WG_CONF}" > "${WG_CONF}.tmp"
  mv "${WG_CONF}.tmp" "${WG_CONF}"

  wg_apply
  rm -f "${client_conf}"
  echo "OK"
}

wg_get_config() {
  local name="$1"
  wg_sanitize_name "${name}" || { echo "bad name" >&2; exit 4; }
  local path="${CLIENTS_DIR}/${name}.conf"
  [[ -f "${path}" ]] || exit 6
  cat "${path}"
}

wg_disable_client() {
  local name="$1"
  wg_sanitize_name "${name}" || { echo "bad name" >&2; exit 4; }
  awk -v marker="# vpnweb:${name}" '
    $0 == marker {skip=1; saw_peer=0; next}
    skip && /^\[Peer\]$/ {saw_peer=1; next}
    skip && saw_peer && NF==0 {skip=0; saw_peer=0; next}
    skip {next}
    {print}
  ' "${WG_CONF}" > "${WG_CONF}.tmp"
  mv "${WG_CONF}.tmp" "${WG_CONF}"
  wg_apply
  echo "OK"
}

wg_enable_client() {
  local name="$1"
  wg_sanitize_name "${name}" || { echo "bad name" >&2; exit 4; }
  local conf="${CLIENTS_DIR}/${name}.conf"
  [[ -f "${conf}" ]] || exit 6

  if grep -q "^# vpnweb:${name}$" "${WG_CONF}"; then
    echo "OK"
    return
  fi

  local c_priv c_pub psk addr
  c_priv="$(awk -F' = ' '/^PrivateKey = /{print $2; exit}' "${conf}")"
  psk="$(awk -F' = ' '/^PresharedKey = /{print $2; exit}' "${conf}")"
  addr="$(awk -F' = ' '/^Address = /{print $2; exit}' "${conf}")"
  c_pub="$(printf '%s' "${c_priv}" | wg pubkey)"

  cat >> "${WG_CONF}" <<PEER

# vpnweb:${name}
[Peer]
PublicKey = ${c_pub}
PresharedKey = ${psk}
AllowedIPs = ${addr}
PEER
  wg_apply
  echo "OK"
}

case "${cmd}" in
  status)
    if ip link show wg0 >/dev/null 2>&1; then
      echo "active"
    else
      echo "inactive"
    fi
    ;;
  start)
    wg-quick up wg0 >/dev/null
    ;;
  stop)
    wg-quick down wg0 >/dev/null
    ;;
  restart)
    wg-quick down wg0 >/dev/null 2>&1 || true
    wg-quick up wg0 >/dev/null
    ;;
  create-client)
    [[ -n "${arg3}" ]] || usage
    wg_create_client "${arg3}"
    ;;
  delete-client)
    [[ -n "${arg3}" ]] || usage
    wg_delete_client "${arg3}"
    ;;
  disable-client)
    [[ -n "${arg3}" ]] || usage
    wg_disable_client "${arg3}"
    ;;
  enable-client)
    [[ -n "${arg3}" ]] || usage
    wg_enable_client "${arg3}"
    ;;
  get-config)
    [[ -n "${arg3}" ]] || usage
    wg_get_config "${arg3}"
    ;;
  *)
    usage
    ;;
esac
