<?php

declare(strict_types=1);

namespace App;

final class Config
{
    private array $data = [];

    public function __construct()
    {
        $envPath = '/opt/vpnweb/.env';
        if (!file_exists($envPath)) {
            $envPath = dirname(__DIR__) . '/.env';
        }

        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $this->data[trim($k)] = trim($v);
            }
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->data[$key] ?? $default;
    }
}
