<?php
/** auth/login_cancel_mfa.php — abandon pending MFA challenge or forced setup. */
require_once dirname(__DIR__) . '/config/config.php';
Auth::start();
unset(
    $_SESSION['mfa_pending_user_id'],
    $_SESSION['mfa_setup_required'],
    $_SESSION['mfa_setup_secret'],
    $_SESSION['mfa_setup_codes_once']
);
header('Location: login.php');
exit;