<?php
// Admin only: registers (or finds) this add-on's PayPal webhook and stores its id + mode.
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''), 'palette_setup')) {
        http_response_code(400);
        $result = ['ok' => false, 'error' => 'csrf'];
    } else {
        $result = palette_setup_webhook($conn);
    }
}
$s = palette_settings($conn);
$token = generateCSRFToken('palette_setup');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Site to Design Token setup</title>
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:32px auto;padding:0 16px;color:#1c1f26;background:#fff}
code{background:#f0f2f6;padding:2px 4px;border-radius:4px}.ok{color:#0b6b57}.err{color:#b42318}
button{padding:8px 16px;font-size:15px;cursor:pointer}</style></head>
<body>
<h1>Site to Design Token: PayPal webhook</h1>
<p>Webhook URL: <code><?= palette_h(PALETTE_WEBHOOK_URL) ?></code></p>
<p>Current webhook id: <code><?= palette_h($s['webhook_id'] !== '' ? (string)$s['webhook_id'] : '(none)') ?></code>
   mode: <code><?= palette_h($s['webhook_mode'] !== '' ? (string)$s['webhook_mode'] : '(none)') ?></code></p>
<?php if ($result !== null): ?>
  <?php if ($result['ok']): ?>
    <p class="ok"><?= $result['reused'] ? 'Reused the existing webhook' : 'Registered a new webhook' ?>
      <code><?= palette_h($result['webhook_id']) ?></code> (<?= palette_h($result['mode']) ?>).</p>
  <?php else: ?>
    <p class="err">Setup failed: <?= palette_h($result['error'] === 'csrf' ? 'the form expired, reload and try again.' : palette_t('en', $result['error'])) ?></p>
  <?php endif; ?>
<?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= palette_h($token) ?>">
  <button type="submit">Register or find the webhook</button>
</form>
</body></html>
