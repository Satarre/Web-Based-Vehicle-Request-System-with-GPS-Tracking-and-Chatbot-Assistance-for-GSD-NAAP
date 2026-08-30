<?php
/** staff/fleet.php — GPS tracking moved to shared tracking.php (Phase 4). */
require_once dirname(__DIR__) . '/config/config.php';
header('Location: ' . BASE_URL . 'tracking.php');
exit;
