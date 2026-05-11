<?php

declare(strict_types=1);

namespace App;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function provisioningLabel(string $issuer, string $username): string
    {
        return rawurlencode($issuer . ':' . $username);
    }

    public function otpauthUrl(string $issuer, string $username, string $secret): string
    {
        $label = $this->provisioningLabel($issuer, $username);
        $issuerQ = rawurlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerQ}&algorithm=SHA1&digits=6&period=30";
    }

    public function randomSecret(int $length = 32): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $digits = preg_replace('/\D/', '', $code ?? '') ?? '';
        if (strlen($digits) !== 6) {
            return false;
        }

        $slice = intdiv(time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->at($secret, $slice + $i), $digits)) {
                return true;
            }
        }

        return false;
    }

    private function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return '000000';
        }

        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $input): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        $bits = '';

        for ($i = 0, $len = strlen($clean); $i < $len; $i++) {
            $idx = strpos(self::ALPHABET, $clean[$i]);
            if ($idx === false) {
                return '';
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        for ($i = 0, $len = strlen($bits); $i + 8 <= $len; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }

        return $out;
    }
}
