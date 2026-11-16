<?php
declare(strict_types=1);
/**
 * director/chatbot.php — redirects SAO/Director to the shared chatbot-FAQ
 * manager (mis/chatbot.php), which now serves roles 1 and 2.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::requirePage($pdo, [2]);
header('Location: ' . BASE_URL . 'mis/chatbot.php');
exit;