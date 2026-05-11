<?php

declare(strict_types=1);

namespace App;

final class SecretCipher
{
    private string $key;

    public function __construct(private Config $config)
    {
        $material = (string)$config->get('APP_SECRET', 'vpnweb-dev-secret-change-me');
        $this->key = hash('sha256', $material, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher)) {
            throw new \RuntimeException('encrypt failed');
        }

        return 'enc1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'enc1:')) {
            return null;
        }

        $raw = base64_decode(substr($payload, 5), true);
        if (!is_string($raw) || strlen($raw) < 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : null;
    }
}
