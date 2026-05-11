<?php

declare(strict_types=1);

namespace App;

use App\Adapters\OpenVpnAdapter;
use App\Adapters\VpnAdapterInterface;
use App\Adapters\WireGuardAdapter;
use PDO;
use RuntimeException;

final class VpnService
{
    private VpnAdapterInterface $adapter;

    public function __construct(private Config $config, private PDO $pdo)
    {
        $backend = strtolower((string)$config->get('VPN_BACKEND', 'wireguard'));
        $this->adapter = $backend === 'openvpn' ? new OpenVpnAdapter() : new WireGuardAdapter();
    }

    public function backend(): string
    {
        return strtolower((string)$this->config->get('VPN_BACKEND', 'wireguard'));
    }

    public function serviceStatus(): string
    {
        return $this->adapter->status();
    }

    public function serviceAction(string $action): void
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return;
        }
        shell_exec('sudo /opt/vpnweb/bin/vpnctl ' . $action . ' ' . escapeshellarg($this->backend()) . ' 2>/dev/null');
    }

    public function clients(): array
    {
        $stmt = $this->pdo->query('SELECT id, client_name, assigned_ip, external_id, status, created_at FROM vpn_clients ORDER BY id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createClient(string $name, int $userId): void
    {
        $backend = $this->backend();
        $raw = trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl create-client ' . escapeshellarg($backend) . ' ' . escapeshellarg($name) . ' 2>&1'));
        $output = $this->extractOkLine($raw);

        if ($output === null || !str_starts_with($output, 'OK|')) {
            throw new RuntimeException($raw !== '' ? $raw : 'create failed');
        }

        $parts = explode('|', $output);
        $externalId = $parts[1] ?? $name;
        $assignedIp = $parts[2] ?? null;

        $stmt = $this->pdo->prepare("INSERT INTO vpn_clients(backend, client_name, assigned_ip, external_id, status, created_by, created_at, updated_at) VALUES (:b,:n,:ip,:e,'active',:u,datetime('now'),datetime('now'))");
        $stmt->execute([':b' => $backend, ':n' => $name, ':ip' => $assignedIp, ':e' => $externalId, ':u' => $userId]);
    }

    public function setClientStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT external_id FROM vpn_clients WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['external_id'])) {
            throw new RuntimeException('client not found');
        }

        $backend = $this->backend();
        $cmd = $status === 'active' ? 'enable-client' : 'disable-client';
        $raw = trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl ' . $cmd . ' ' . escapeshellarg($backend) . ' ' . escapeshellarg((string)$row['external_id']) . ' 2>&1'));
        $output = $this->extractOkLine($raw) ?? $raw;
        if ($output !== 'OK') {
            throw new RuntimeException($raw !== '' ? $raw : 'status change failed');
        }

        $upd = $this->pdo->prepare("UPDATE vpn_clients SET status=:s, updated_at=datetime('now') WHERE id=:id");
        $upd->execute([':s' => $status, ':id' => $id]);
    }

    public function deleteClient(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT external_id FROM vpn_clients WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['external_id'])) {
            throw new RuntimeException('client not found');
        }

        $backend = $this->backend();
        $raw = trim((string)shell_exec('sudo /opt/vpnweb/bin/vpnctl delete-client ' . escapeshellarg($backend) . ' ' . escapeshellarg((string)$row['external_id']) . ' 2>&1'));
        $output = $this->extractOkLine($raw) ?? $raw;
        if ($output !== 'OK') {
            throw new RuntimeException($raw !== '' ? $raw : 'delete failed');
        }

        $upd = $this->pdo->prepare("UPDATE vpn_clients SET status='revoked', revoked_at=datetime('now'), updated_at=datetime('now') WHERE id=:id");
        $upd->execute([':id' => $id]);
    }

    public function getClientConfig(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT client_name, external_id, status FROM vpn_clients WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['external_id'])) {
            throw new RuntimeException('client not found');
        }

        $backend = $this->backend();
        $content = (string)shell_exec('sudo /opt/vpnweb/bin/vpnctl get-config ' . escapeshellarg($backend) . ' ' . escapeshellarg((string)$row['external_id']) . ' 2>/dev/null');
        if ($content === '') {
            throw new RuntimeException('config unavailable');
        }

        return [
            'name' => (string)$row['client_name'],
            'status' => (string)$row['status'],
            'content' => $content,
        ];
    }

    private function extractOkLine(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, 'OK')) {
                return $line;
            }
        }
        return null;
    }
}
