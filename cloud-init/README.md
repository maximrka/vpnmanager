# Cloud-init Quick Start

Use `vpnweb-user-data.yaml` as user-data when creating a new VPS.

## What it does

1. Updates system packages
2. Installs basic dependencies (`git`, `curl`, `openssl`)
3. Clones `VPNWEB_REPO`
4. Runs `install.sh` non-interactively
5. Writes full bootstrap logs to `/var/log/vpnweb-bootstrap.log`

## Customize before launch

Edit these variables in `vpnweb-user-data.yaml`:

- `VPNWEB_REPO` (repo URL)
- `VPNWEB_REF` (branch/tag)
- `VPNWEB_BACKEND` (`wireguard` or `openvpn`)

## After first boot

SSH to server and check:

```bash
sudo tail -n 200 /var/log/vpnweb-bootstrap.log
sudo cat /root/vpnweb-initial-admin.txt
sudo cat /etc/vpnweb-network-summary
```
