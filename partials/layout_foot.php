<?php
/**
 * partials/layout_foot.php — closes shell, loads shared JS.
 * Expects: $USER (optional), $IDLE_TIMEOUT (int seconds, optional).
 * Pages needing to stay alive (driver mid-trip) set:
 *   <body data-keep-session="1"> via $KEEP_SESSION = true BEFORE including layout_head.
 */
$keepSession = !empty($KEEP_SESSION) ? '1' : '0';
?>
</div><!-- /.main -->
</div><!-- /.shell -->
<?php if (!isset($NO_SETTINGS_DRAWER) || !$NO_SETTINGS_DRAWER) { include __DIR__ . '/settings_drawer.php'; } ?>
<?php if (($USER['role_id'] ?? 0) === 6) { include __DIR__ . '/chatbox.php'; } ?>
<div class="modal-backdrop" id="confirmModal">
  <div class="modal-body" style="max-width:360px">
    <div class="modal-head"><span>Confirm</span>
      <button class="modal-close" type="button" onclick="UI.modal('confirmModal', false)"><?= function_exists('icon') ? icon('x') : '×' ?></button>
    </div>
    <div class="px-[18px] py-4"><p id="confirmMessage" class="m-0 font-semibold">Are you sure?</p></div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="btn btn-ghost" id="confirmCancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="confirmOk" type="button">Confirm</button>
    </div>
  </div>
</div>
<script>window.APP_IDLE_TIMEOUT = <?= (int) ($IDLE_TIMEOUT ?? Settings::getInt('IDLE_TIMEOUT_SEC', 300)) ?>;
document.body.dataset.keepSession = '<?= $keepSession ?>';</script>
<?php
$assetV = static fn(string $f): string => BASE_URL . 'assets/js/' . $f . '?v=' . filemtime(dirname(__DIR__) . '/assets/js/' . $f);
?>
<script src="<?= $assetV('error_logger.js') ?>"></script>
<script src="<?= $assetV('api.js') ?>"></script>
<script src="<?= $assetV('ui.js') ?>"></script>
<script src="<?= $assetV('settings.js') ?>"></script>
<script src="<?= $assetV('TrackingClient.js') ?>"></script>
<script src="<?= $assetV('session_guard.js') ?>"></script>
<script src="<?= $assetV('idle.js') ?>"></script>
<script src="<?= $assetV('pages/table_pager.js') ?>"></script>
<?php foreach (($PAGE_SCRIPTS ?? []) as $s): ?>
<script src="<?= BASE_URL ?>assets/js/pages/<?= htmlspecialchars($s) ?>?v=<?= filemtime(dirname(__DIR__) . '/assets/js/pages/' . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
