<?php
/**
 * partials/chatbox.php — GSD Assistant chat panel (personnel only).
 * Included from layout_foot.php when $USER['role_id'] === 6.
 * JS loads via $PAGE_SCRIPTS so it runs AFTER api.js.
 */
$PAGE_SCRIPTS = array_values(array_unique(array_merge($PAGE_SCRIPTS ?? [], ['chatbot.js', 'location-autocomplete.js'])));
/* The guided request wizard geocodes the destination with the shared Places
 * loader; it only fetches Google's JS when a step actually needs it. */
$VENDOR_SCRIPTS = array_values(array_unique(array_merge($VENDOR_SCRIPTS ?? [], ['maps_loader.js'])));
?>
<script>window.NAAP_MAPS_KEY = <?= json_encode(GOOGLE_MAPS_API_KEY) ?>;</script>
<section class="chat-panel" id="chatPanel" hidden aria-label="GSD Assistant">
  <div class="flex items-center justify-between p-3 px-3 border-b border-border font-bold text-md">
    <span class="flex items-center gap-2"><?= icon('bot') ?> GSD Assistant</span>
    <button class="icon-btn" type="button" id="chatClose"><?= icon('x') ?></button>
  </div>
  <div class="chat-browse" id="chatBrowse" hidden></div>
  <div class="chat-body" id="chatBody"></div>
  <div class="chat-topics-bar" id="chatTopicsBar" hidden></div>
  <form class="flex gap-1.5 p-2.5 px-3 border-t border-border" id="chatForm">
    <input type="text" id="chatText" placeholder="Ask about trips, tickets" autocomplete="off" maxlength="300">
    <button class="btn btn-sm btn-primary shrink-0" type="submit">Send</button>
  </form>
</section>
