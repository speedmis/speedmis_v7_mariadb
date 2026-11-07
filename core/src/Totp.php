<?php
namespace App;

/**
 * Lightweight TOTP (RFC 6238) — Google Authenticator compatible.
 * No external dependencies.
 */
class Totp
{
    private const PERIOD  = 30;
    private const DIGITS  = 6;
    private const ALGO    = 'sha1';
    private const B32     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 160-bit (20 byte) 랜덤 시크릿 생성 → Base32 */
    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        return self::base32Encode($raw);
    }

    /** 현재 시간의 TOTP 코드 */
    public static function getCode(string $secret, ?int $time = null): string
    {
        $time ??= time();
        $counter = intdiv($time, self::PERIOD);
        $raw = self::base32Decode($secret);
        $bin = pack('N*', 0, $counter);              // 64-bit big-endian
        $hash = hash_hmac(self::ALGO, $bin, $raw, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** 코드 검증 (±1 윈도우) */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        if (strlen($code) !== self::DIGITS) return false;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::getCode($secret, $now + $i * self::PERIOD), $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// URI (앱 등록용) */
    public static function getUri(string $secret, string $account, string $issuer = 'SpeedMIS'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** QR 코드 URL (자체 PNG 생성, 실패 시 Google Charts API 폴백) */
    public static function getQrUrl(string $secret, string $account, string $issuer = 'SpeedMIS', int $size = 200): string
    {
        $uri = self::getUri($secret, $account, $issuer);
        try {
            $dataUri = QrCode::toDataUri($uri, $size);
            if ($dataUri && strlen($dataUri) > 100) return $dataUri;
        } catch (\Throwable $e) {}
        // 폴백: Google Charts API
        return 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size
             . '&chl=' . rawurlencode($uri) . '&choe=UTF-8';
    }

    // ── Base32 helpers ──

    private static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $ch) {
            $binary .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::B32[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $binary = '';
        for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
            $pos = strpos(self::B32, $b32[$i]);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) break;
            $out .= chr(bindec($byte));
        }
        return $out;
    }
}
