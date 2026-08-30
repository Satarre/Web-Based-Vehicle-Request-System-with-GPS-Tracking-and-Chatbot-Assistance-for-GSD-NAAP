<?php
/** auth/logout.php — manual logout. */
require_once dirname(__DIR__) . '/config/config.php';
Auth::logout($pdo);
header('Location: ' . BASE_URL . 'auth/login.php');
exit;
