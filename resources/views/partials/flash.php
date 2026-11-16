<?php
/** Flash messages are passed to app.js as JSON (no inline script execution). */
$messages = \App\Core\Session::getFlash('messages', []);
if ($messages): ?>
<script type="application/json" id="flash-data"><?= json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
