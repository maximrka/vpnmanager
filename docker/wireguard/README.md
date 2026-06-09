# Docker WireGuard Mode

This directory contains an isolated Docker deployment mode for the
WireGuard-only variant of `vpnmanager`.

Included files:
- `Dockerfile`
- `docker-compose.yml`
- `entrypoint.sh`
- `vpnctl.sh`

Current design:
- one container
- Apache + PHP + SQLite inside the container
- WireGuard inside the same container
- `network_mode: host`
- persistent volumes for `/etc/wireguard` and `/opt/vpnweb/var`

Quick start:

```bash
cd docker/wireguard
docker compose build
WG_HOST=your.public.ip.or.domain docker compose up -d
docker logs vpnmanager-wg
```

By default:
- panel listens on `http://HOST:8080`
- WireGuard listens on `51820/udp`
- initial admin password is printed to logs and stored in `/opt/vpnweb/var/initial-admin.txt`

Useful environment variables:

- `WG_HOST` - public IP or DNS placed into generated client configs
- `WG_PORT` - WireGuard UDP port
- `APACHE_PORT` - web panel port inside host network mode
- `WG_ENABLE_IPV6` - `auto`, `1`, or `0`
- `ADMIN_PASSWORD` - optional fixed initial admin password
- `APP_NAME` - panel title
- `APP_LOGO_TEXT` - short logo text in UI
- `TOTP_ISSUER` - TOTP issuer label for Google Authenticator

Requirements:

- Linux host with Docker and Compose plugin
- `/dev/net/tun` available on host
- host kernel with WireGuard support
- host firewall allowing chosen panel port and `51820/udp`

Current limitations:

- WireGuard only, no OpenVPN in this Docker mode yet
- designed around `network_mode: host`, not bridge mode
- first start initializes the database and admin user automatically
- if you change core network settings later, the easiest path is recreating the container with persisted volumes kept intentionally

Reset admin password inside container:

```bash
docker exec -it vpnmanager-wg /opt/vpnweb/bin/vpnweb-admin-passwd
```

Notes:
- this mode is intentionally isolated on branch `docker-wireguard`
- it does not replace the existing bare-metal installer flow
- the current implementation targets WireGuard only
