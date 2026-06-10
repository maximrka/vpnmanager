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

    public function clientSessions(): array
    {
        $raw = trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl session-stats wireguard 2>/dev/null'));
        return $this->parseSessionLines($raw);
    }

    private function parseSessionLines(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $stats = [];
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$name, $state, $rx, $tx, $lastSeen, $endpoint] = array_pad(explode('|', $line, 6), 6, '');
            if ($name === '') {
                continue;
            }

            $stats[$name] = [
                'session_state' => $state !== '' ? $state : 'offline',
                'rx_bytes' => ctype_digit($rx) ? (int)$rx : 0,
                'tx_bytes' => ctype_digit($tx) ? (int)$tx : 0,
                'last_seen' => $lastSeen,
                'endpoint' => $endpoint,
            ];
        }

        return $stats;
    }
}
