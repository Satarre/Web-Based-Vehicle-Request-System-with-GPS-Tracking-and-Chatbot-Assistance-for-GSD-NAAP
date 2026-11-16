<?php
/**
 * partials/shell_open.php — opens .page-wrap, renders sidebar + topbar.
 * Include AFTER layout_head.php. Expects $USER, $PAGE_TITLE, optional $ACTIVE.
 */
?>
<div class="page-wrap">
<?php
require_once __DIR__ . '/sidebar.php';
?>
<div class="main">
<?php require_once __DIR__ . '/topbar.php'; ?>
<main class="main-pad">
