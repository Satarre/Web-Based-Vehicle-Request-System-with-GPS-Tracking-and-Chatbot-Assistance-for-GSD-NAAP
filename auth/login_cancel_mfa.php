<?php
/** auth/login_cancel_mfa.php — abandon pending MFA challenge. */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();
unset($_SESSION['mfa_pending_user_id']);
header('Location: login.php');
exit;
