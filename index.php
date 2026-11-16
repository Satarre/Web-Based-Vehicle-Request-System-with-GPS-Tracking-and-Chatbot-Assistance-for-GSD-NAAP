<?php
/**
 * index.php — entry point: bounce to the auth portal.
 * Relative Location works at any deployment path (domain root or subfolder);
 * the app's internal redirects use BASE_URL (config.php / secrets.php).
 */
header('Location: auth/login.php');
exit;
