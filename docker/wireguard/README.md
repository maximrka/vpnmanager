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
- Docker bridge networking with published ports
- persistent volumes for `/etc/wireguard` and `/opt/vpnweb/var`

Quick start:

```bash
cd docker/wireguard
docker compose build
docker compose up -d
docker logs vpnmanager-wg
```

By default:
- panel listens on `http://HOST:8080`
- WireGuard listens on `51820/udp`
- initial admin password is printed to logs and stored in `/opt/vpnweb/var/initial-admin.txt`

Useful environment variables:

- `WG_HOST` - optional public IP or DNS placed into generated client configs
- `WG_PORT` - WireGuard UDP port
- `APACHE_PORT` - web panel port inside the container; keep it aligned with `ports:`
- `APP_SECRET` - optional fixed app secret for stable encrypted 2FA data across container recreation
- `WG_ENABLE_IPV6` - `auto`, `1`, or `0`
- `ADMIN_PASSWORD` - optional fixed initial admin password
- `APP_NAME` - panel title
- `APP_LOGO_TEXT` - short logo text in UI
- `TOTP_ISSUER` - TOTP issuer label for Google Authenticator

Requirements:

- Linux host with Docker and Compose plugin
- `/dev/net/tun` available on host
- host kernel with WireGuard support
- host firewall allowing `8080/tcp` and `51820/udp`

Current limitations:

- WireGuard only, no OpenVPN in this Docker mode yet
- IPv6 in Docker mode depends on Docker host/network support and may need extra tuning
- first start initializes the database and admin user automatically
- generated app secret is persisted in `/opt/vpnweb/var/runtime.env`, so TOTP 2FA survives container recreation
- if you change core network settings later, the easiest path is recreating the container with persisted volumes kept intentionally
- auto-detection of public IP tries an external lookup first; if it is wrong, set `WG_HOST` manually in `docker-compose.yml`

Reset admin password inside container:

```bash
docker exec -it vpnmanager-wg /opt/vpnweb/bin/vpnweb-admin-passwd
```

Notes:
- this mode is intentionally isolated on branch `docker-wireguard`
- it does not replace the existing bare-metal installer flow
- the current implementation targets WireGuard only
