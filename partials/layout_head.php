<?php
/**
 * partials/layout_head.php
 * Expects: $PAGE_TITLE (string), optional $USER (Auth user array).
 */
if (!isset($PAGE_TITLE)) { $PAGE_TITLE = 'NAAP GSD Trip Ticket'; }
require_once dirname(__DIR__) . '/config/config.php';
Csrf::token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($PAGE_TITLE) ?> · NAAP GSD Trip Ticket</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/VTS_main.png">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/tailwind.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/tailwind.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<?= $HEAD_EXTRAS ?? '' ?>
<script>
/* Apply saved theme before first paint (no flash). */
(function () {
  var t = localStorage.getItem('naap_vts_theme')
    || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
const BASE_URL   = <?= json_encode(BASE_URL) ?>;
const CSRF_TOKEN = <?= json_encode(Csrf::token()) ?>;
</script>
</head>
<body>
