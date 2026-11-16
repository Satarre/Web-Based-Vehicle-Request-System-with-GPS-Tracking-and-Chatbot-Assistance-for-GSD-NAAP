<?php
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Direct access forbidden.");
}

/**
 * totp.php - Standalone TOTP (RFC 6238) implementation
 * ─────────────────────────────────────────────────────────────────
 * Place at: capstone/includes/totp.php
 *
 * No Composer needed. Works with Google Authenticator, Authy, etc.
 * ─────────────────────────────────────────────────────────────────
 */

class TOTP {

    /* ── CONFIG ────────────────────────────────────────────────── */
    private const DIGITS    = 6;      // OTP length
    private const PERIOD    = 30;     // seconds per code window
    private const ALGORITHM = 'sha1'; // HMAC algorithm (GA uses sha1)
    private const WINDOW    = 1;      // allow ±1 window for clock drift

    /* ─────────────────────────────────────────────────────────────
       Generate a random Base32 secret (used once per account setup)
       ───────────────────────────────────────────────────────────── */
    public static function generateSecret(int $bytes = 20): string {
        return self::base32Encode(random_bytes($bytes));
    }

    /* ─────────────────────────────────────────────────────────────
       Build the otpauth:// URI for QR code generation
       ───────────────────────────────────────────────────────────── */
    public static function getUri(string $secret, string $label, string $issuer = 'Digital Trip Ticket'): string {
        $label  = rawurlencode($label);
        $issuer = rawurlencode($issuer);
        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /* ─────────────────────────────────────────────────────────────
       Verify a user-submitted OTP code against the secret
       Returns true if the code is valid (±WINDOW time steps)
       ───────────────────────────────────────────────────────────── */
    public static function verify(string $secret, string $code): bool {
        return self::verifyStep($secret, $code) !== null;
    }

    /**
     * Verify a code and return the matched time step (for replay protection),
     * or null when the code is invalid.
     */
    public static function verifyStep(string $secret, string $code): ?int {
        $code = preg_replace('/\s+/', '', $code); // strip spaces
        if (!preg_match('/^\d{6}$/', $code)) return null;

        $timeStep = (int) floor(time() / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $timeStep + $i;
            if (hash_equals(self::generate($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /* ─────────────────────────────────────────────────────────────
       Internal: generate a TOTP code for a given time step
       ───────────────────────────────────────────────────────────── */
    private static function generate(string $secret, int $timeStep): string {
        $key     = self::base32Decode($secret);
        $time    = pack('N*', 0) . pack('N*', $timeStep); // 8-byte big-endian
        $hash    = hash_hmac(self::ALGORITHM, $time, $key, true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ( ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /* ─────────────────────────────────────────────────────────────
       Base32 encode/decode (RFC 4648)
       ───────────────────────────────────────────────────────────── */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $data): string {
        $binary = '';
        foreach (str_split($data) as $byte) {
            $binary .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $binary .= str_repeat('0', (5 - strlen($binary) % 5) % 5);
        $result  = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= self::BASE32_CHARS[bindec($chunk)];
        }
        return $result;
    }

    private static function base32Decode(string $data): string {
        $data   = strtoupper(preg_replace('/\s+/', '', $data));
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos     = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) $result .= chr(bindec($byte));
        }
        return $result;
    }
}
