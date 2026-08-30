<?php
/**
 * index.php — entry point: bounce to the auth portal.
 */
header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/NAAP_MN_VTS_Capstone/') . 'auth/login.php');
exit;
