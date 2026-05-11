<?php

declare(strict_types=1);

namespace App\Adapters;

final class WireGuardAdapter implements VpnAdapterInterface
{
    public function serviceName(): string
    {
        return 'wg-quick@wg0';
    }

    public function status(): string
    {
        return trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl status wireguard 2>/dev/null')) ?: 'unknown';
    }
}
