<?php
/**
 * core/Settings.php — typed access to app_settings with per-request cache.
 */

class Settings
{
    private static ?PDO $pdo   = null;
    private static ?array $all = null;

    public static function init(PDO $pdo): void
    {
        self::$pdo  = $pdo;
        self::$all  = null;
    }

    /** Raw string value, or $default when missing/empty. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        $val = $all[$key] ?? null;
        return ($val === null || $val === '') ? $default : $val;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? 'true' : 'false');
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, (string) $default);
    }

    /** @return array<string,string> all key => value pairs (cached) */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        self::$all = [];
        $rows = self::$pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::$all = $rows ?: [];
        return self::$all;
    }

    /** Persist one setting (MIS settings page). */
    public static function set(string $key, string $value, int $updatedBy): bool
    {
        $stmt = self::$pdo->prepare(
            'UPDATE app_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?'
        );
        $ok  = $stmt->execute([$value, $updatedBy, $key]);
        self::$all = null;
        return $ok;
    }
}
