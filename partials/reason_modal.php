<?php
/**
 * partials/reason_modal.php — generic reason-gathering modal.
 * Expects: $RM_ID, $RM_TITLE, $RM_OK; optional $RM_LABEL, $RM_DANGER, $RM_PLACEHOLDER.
 * JS reads #{$RM_ID}Text and #{$RM_ID}Ok.
 */
$rmId     = (string) $RM_ID;
$rmLabel  = $RM_LABEL ?? 'Reason';
$rmDanger = !empty($RM_DANGER);
$okCls = $rmDanger ? 'btn btn-danger' : 'btn btn-primary';
$cancelCls = 'btn btn-ghost';
?>
<div class="modal-backdrop" id="<?= htmlspecialchars($rmId) ?>">
  <div class="modal-body" style="max-width:420px">
    <div class="modal-head">
      <span><?= htmlspecialchars($RM_TITLE) ?></span>
      <button class="modal-close" type="button" onclick="UI.modal('<?= htmlspecialchars($rmId) ?>', false)"><?= icon('x') ?></button>
    </div>
    <div class="px-[18px] py-4">
      <label class="form-label"><?= htmlspecialchars($rmLabel) ?></label>
      <textarea id="<?= htmlspecialchars($rmId) ?>Text" rows="3" maxlength="500"
                class="form-input"
                placeholder="<?= htmlspecialchars($RM_PLACEHOLDER ?? 'State the reason (required)') ?>"></textarea>
    </div>
    <div class="px-[18px] py-3 border-t border-border flex justify-end gap-2">
      <button class="<?= $cancelCls ?>" type="button" onclick="UI.modal('<?= htmlspecialchars($rmId) ?>', false)">Close</button>
      <button class="<?= $okCls ?>" id="<?= htmlspecialchars($rmId) ?>Ok"><?= htmlspecialchars($RM_OK) ?></button>
    </div>
  </div>
</div>
<?php unset($rmId, $rmLabel, $rmDanger, $okCls, $cancelCls); ?>
