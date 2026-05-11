# VPN Web Panel MVP v1 Spec

## 1) Goal
Build a self-hosted VPN management panel that can be deployed with one script on Debian/Ubuntu.
The installer configures one selected VPN backend (`wireguard` or `openvpn`), deploys a PHP web UI on Apache, and enables secure admin access with optional TOTP 2FA.

## 2) Supported OS (v1)
- Debian 12
- Debian 13
- Ubuntu 22.04
- Ubuntu 24.04

Out of scope for v1: other distros, Nginx, containers, HA setup.

## 3) Core UX
### First-run
1. User clones repo.
2. User runs `sudo ./install.sh`.
3. Installer detects OS and asks backend choice:
   - WireGuard
   - OpenVPN
4. Installer configures VPN backend, Apache+PHP app, SQLite, system services, permissions.
5. Installer prints panel URL and initial admin credentials.

### Admin login and 2FA
1. First login is username + password only.
2. In profile, admin can enable 2FA (TOTP via Google Authenticator compatible apps).
3. Setup flow:
   - show QR code + manual key
   - verify one TOTP code
   - generate backup codes
4. After enabling, login requires password + TOTP.

## 4) MVP Features
### Authentication / Security
- Local auth: username + password hash (Argon2id if available, fallback bcrypt).
- Session-based auth for panel.
- Optional 2FA (TOTP), enabled by user in profile.
- Backup recovery codes for 2FA.
- CSRF token on state-changing actions.
- Rate limit login attempts (basic local throttle).

### VPN management
Common operations (for both backends where applicable):
- List clients
- Create client
- Disable client
- Enable client
- Revoke/Delete client
- Download client config file
- View client connection status (best-effort)

WireGuard-specific:
- Show QR for client config

Server controls:
- Show backend service status
- Start service
- Stop service
- Restart service

### Audit
- Record admin actions in audit log (who, what, when, target, result).

## 5) Architecture
- `install.sh` - top-level installer and OS detection.
- `scripts/install_common.sh` - shared packages, Apache/PHP, DB init.
- `scripts/install_wireguard.sh` - WireGuard backend install/setup.
- `scripts/install_openvpn.sh` - OpenVPN backend install/setup.
- `scripts/sudo-actions.sh` - privileged command wrapper with strict allowlist.
- `web/` - PHP application.

Web UI does not run privileged commands directly. It invokes controlled actions through `sudo` + `sudo-actions.sh` allowlist.

## 6) Data model (SQLite)
Tables:
- `users`
  - `id`, `username`, `password_hash`, `is_active`, `created_at`, `updated_at`
- `user_2fa`
  - `user_id`, `totp_secret_enc`, `is_enabled`, `enabled_at`
- `user_backup_codes`
  - `id`, `user_id`, `code_hash`, `used_at`, `created_at`
- `vpn_clients`
  - `id`, `backend`, `client_name`, `external_id`, `status`, `created_by`, `created_at`, `updated_at`, `revoked_at`
- `audit_log`
  - `id`, `user_id`, `action`, `target_type`, `target_id`, `details_json`, `result`, `created_at`, `ip`
- `app_config`
  - `key`, `value`, `updated_at`

Notes:
- VPN private keys/certs are stored on filesystem with strict permissions, not in DB.
- DB stores metadata and references only.

## 7) Security model
- Least privilege for web user (`www-data`).
- Only required commands whitelisted in `/etc/sudoers.d/vpnweb`.
- Input validation for client names and action params.
- No shell interpolation of untrusted input.
- File download paths resolved and checked against allowed directories.
- Sensitive values never logged in plaintext.

## 8) Non-goals (v1)
- Multi-tenant org model
- External IdP/SSO
- API for third-party clients
- Clustered VPN servers
- Forced 2FA policy per org

## 9) Deliverables for v1
1. One-command installer (`install.sh`) for Debian/Ubuntu.
2. Working web panel with auth + optional TOTP 2FA.
3. Backend selection (OpenVPN or WireGuard) during install.
4. Client lifecycle actions + config download + service controls.
5. Audit log view.
6. README with install and recovery instructions.

## 10) Milestones
1. Scaffold project and installer skeleton.
2. Implement auth + session + DB schema.
3. Implement 2FA setup/verify/login flow.
4. Implement WireGuard adapter.
5. Implement OpenVPN adapter.
6. Add audit log and hardening.
7. End-to-end test on Debian 12/13 and Ubuntu 22.04/24.04.
