<?php
namespace App\Modules\Identity\Application;
/** RFC 6238, SHA-1, 6 digits, 30 seconds. No external clock requests. */
class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        return implode('', array_map(fn($v) => $alphabet[bindec($v)], str_split($bits, 5)));
    }
    public static function code(string $secret, int $step): string
    {
        $bits = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
    public static function verify(
        string $secret,
        string $code,
        int $lastStep = 0,
        ?int $timestamp = null,
    ): int|false {
        if (!preg_match('/^\d{6}$/D', $code)) {
            return false;
        }
        $step = intdiv($timestamp ?? time(), 30);
        for ($i = $step - 1; $i <= $step + 1; $i++) {
            if ($i > $lastStep && hash_equals(self::code($secret, $i), $code)) {
                return $i;
            }
        }
        return false;
    }
}
