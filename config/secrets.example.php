<?php
/**
 * config/secrets.example.php — template for config/secrets.php (gitignored).
 *
 * Copy to config/secrets.php on each deployment and fill in real values.
 * Prefer environment variables (setenv) over editing this file in prod.
 * NEVER commit config/secrets.php.
 *
 * APP_SECRET_KEY — 32 random bytes, hex/base64. Generate:
 *   php -r "echo bin2hex(random_bytes(32));"
 * Used to AES-256-GCM encrypt TOTP secrets at rest. Rotating it invalidates
 * all stored MFA secrets (users must re-enable MFA).
 *
 * APP_ENV — 'dev' or 'prod'. Defaults to 'prod' when unset. Dev-only
 * behaviors (MFA toggle, inline OTP display) are gated on 'dev'. Set to
 * 'dev' only in local development; keep 'prod' (or omit — it defaults to
 * prod) on any real deployment.
 *
 * GOOGLE_MAPS_API_KEY — client-side Maps JS key. Required, or map pages will
 * not load. Restrict it by HTTP referrer in Google Cloud Console to your domain
 * (and localhost for dev). API restrictions: Maps JavaScript API, Places API,
 * Directions API (Legacy).
 *
 * GOOGLE_ROUTES_API_KEY — server-side key for the Routes API and Roads API
 * (traffic-aware trip routes + ETA + GPS snapping). Required for server-side
 * routing; there is no browser-key fallback. Restrict it by IP address to the
 * server's outbound IP. API restrictions: Routes API, Roads API. When unset,
 * routing degrades to an offline estimate / raw recorded track.
 *
 * DB_* — MySQL connection overrides (optional; default to localhost/root).
 *
 * BASE_URL — the app's URL prefix. Hostinger at the domain root: '/'.
 * In a subfolder (e.g. https://yourdomain.com/vts): '/vts/'. Unset = local
 * XAMPP path '/NAAP_MN_VTS_Capstone/'.
 */
define('APP_ENV', 'prod');
define('APP_SECRET_KEY', 'REPLACE_WITH_32_BYTE_HEX');
// define('BASE_URL', '/');
// define('GOOGLE_MAPS_API_KEY', 'AIza...');
// define('GOOGLE_ROUTES_API_KEY', 'AIza...');   // required for server routing (IP-restricted)
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'u123456789_naapvts');
// define('DB_USER', 'u123456789_naap');
// define('DB_PASS', 'YOUR_DB_PASSWORD');

/* Optional SMTP overrides (empty/unset = use app_settings values).
 * Hostinger outgoing mail (from hPanel → Email → SMTP):
 *   host smtp.hostinger.com · port 587 · secure tls
 * The SMTP_USER / SMTP_PASS come from the email account you created. */
// define('ENV_SMTP_HOST', 'smtp.hostinger.com');
// define('ENV_SMTP_PORT', '587');
// define('ENV_SMTP_SECURE', 'tls');
// define('ENV_SMTP_USER', 'you@yourdomain.com');
// define('ENV_SMTP_PASS', 'your-email-password');