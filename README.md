# VPN Web Panel (PHP + Apache + SQLite)

Self-hosted web panel to manage VPN clients for **WireGuard** or **OpenVPN**.

- One-command install on Debian/Ubuntu
- Web UI with login/password + optional TOTP 2FA
- Client lifecycle actions (create/disable/enable/revoke/download)
- Service controls (start/stop/restart)
- Audit log of admin actions

## Supported OS

- Debian 12
- Debian 13
- Ubuntu 22.04
- Ubuntu 24.04

## Quick Start

```bash
git clone <your-repo-url>
cd vpnweb
sudo ./install.sh
```

Installer will:

1. Detect OS
2. Ask backend choice: `WireGuard` or `OpenVPN`
3. Install Apache/PHP/SQLite and VPN backend
4. Configure IP forwarding + NAT/firewall for VPN traffic
5. Deploy app to `/opt/vpnweb`
6. Configure sudo allowlist for privileged VPN operations

After install:

- Panel URL: `http://<server-ip>/`
- Default user: `admin`
- Default password: random 10-15 chars (generated during install)

Generated credentials are also saved to `/root/vpnweb-initial-admin.txt`.
Installer also prints network summary and saves it to `/etc/vpnweb-network-summary`.

## Project Structure

- `install.sh` - entrypoint installer
- `scripts/install_common.sh` - Apache/PHP/app deploy/DB init/sudoers
- `scripts/install_wireguard.sh` - WireGuard setup
- `scripts/install_openvpn.sh` - OpenVPN + easy-rsa PKI setup
- `scripts/install_network.sh` - forwarding + firewall/NAT setup
- `scripts/sudo-actions.sh` - privileged command wrapper (`vpnctl`)
- `web/` - PHP application

## Deployment Paths

- App code: `/opt/vpnweb`
- Public web root symlink: `/var/www/html/vpnweb`
- App env: `/opt/vpnweb/.env`
- SQLite DB: `/opt/vpnweb/var/vpnweb.sqlite`
- Privileged helper: `/opt/vpnweb/bin/vpnctl`
- Sudoers rule: `/etc/sudoers.d/vpnweb`

## Admin Password Reset (Server Command)

If admin password is lost, reset it on server:

```bash
sudo /opt/vpnweb/bin/vpnweb-admin-passwd
```

It generates a new random password (10-15 chars) and prints it.

Set your own password explicitly:

```bash
sudo /opt/vpnweb/bin/vpnweb-admin-passwd "MyNewStrongPass123!"
```

## Authentication & 2FA

- Session-based local auth
- Optional TOTP 2FA (Google Authenticator compatible)
- 2FA can be enabled from Profile page
- Backup codes are generated when 2FA is enabled

## VPN Operations

### Common (WireGuard + OpenVPN)

- Create client
- Disable client
- Enable client
- Revoke client
- Download client config
- Service controls: start/stop/restart

### WireGuard specifics

- Client QR page in web UI
- Client config path: `/etc/wireguard/clients/<name>.conf`

### OpenVPN specifics

- easy-rsa PKI under `/etc/openvpn/easy-rsa`
- Generated client config path: `/etc/openvpn/clients/<name>.ovpn`
- Revoke regenerates CRL and restarts OpenVPN server service

## Important Notes

1. Generated client configs currently contain `REPLACE_WITH_SERVER_IP`.
   Replace it with your public server IP or DNS before connecting.
2. The panel executes privileged actions only via `/opt/vpnweb/bin/vpnctl`.
3. Do not broaden sudoers beyond the provided command path.

## Services

- WireGuard: `wg-quick@wg0`
- OpenVPN: `openvpn-server@server`
- Web: `apache2`

Examples:

```bash
sudo systemctl status apache2
sudo systemctl status wg-quick@wg0
sudo systemctl status openvpn-server@server
```

## Database

SQLite schema file:

- `web/schema.sql`

Main tables:

- `users`
- `user_2fa`
- `user_backup_codes`
- `vpn_clients`
- `audit_log`
- `app_config`

## Security Recommendations

1. Change default admin credentials immediately.
2. Enable 2FA for admin account.
3. Restrict panel access by firewall/VPN/private network.
4. Use HTTPS reverse proxy (or TLS on Apache) in production.
5. Backup `/opt/vpnweb/var/vpnweb.sqlite` and VPN config material.

## Reinstall / Update Notes

If you rerun installer, app files are recopied from `web/` to `/opt/vpnweb/`.
Always keep your repo state in sync with what you want deployed.

For already installed servers, use quick update flow:

```bash
git pull
sudo ./update.sh
```

`update.sh` refreshes web code, helper scripts, DB migration checks, permissions, and restarts Apache without full VPN reinstall.

## Current Status

Implemented and testable in current codebase:

- Installer flow for Debian/Ubuntu
- Web auth + optional TOTP 2FA
- WireGuard client create/disable/enable/revoke/download + QR
- OpenVPN client create/disable/enable/revoke/download
- Audit logging and service controls

Still recommended before production rollout:

- End-to-end validation on fresh VMs for all supported OS versions
- Automatic server IP/DNS injection into generated client configs
- Optional hardening pass (rate limiting, stricter headers, HTTPS defaults)
# vpnmanager
