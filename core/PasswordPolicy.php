<?php
declare(strict_types=1);
/**
 * core/PasswordPolicy.php — THE password strength policy for the whole app.
 * Hardcoded by design: NOT configurable from the frontend / app_settings.
 *
 * Rules: at least 8 characters, with at least one uppercase letter, one
 * lowercase letter, one number, and one special character (any non-alphanumeric).
 *
 * Keep assets/js/password-policy.js in sync — the browser mirrors these rules
 * for instant feedback; the server remains the authority.
 */

class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** Returns the first failure message, or null when the password passes. */
    public static function check(string $pw): ?string
    {
        if (strlen($pw) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (!preg_match('/[A-Z]/', $pw)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $pw)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/\d/', $pw)) {
            return 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $pw)) {
            return 'Password must contain at least one special character.';
        }
        return null;
    }
}
