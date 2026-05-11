<?php

declare(strict_types=1);

namespace App\Adapters;

final class OpenVpnAdapter implements VpnAdapterInterface
{
    public function serviceName(): string
    {
        return 'openvpn-server@server';
    }

    public function status(): string
    {
        return trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl status openvpn 2>/dev/null')) ?: 'unknown';
    }
}
