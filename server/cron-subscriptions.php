<?php
// CLI (daily cron): php cron-subscriptions.php: syncs subscription status and paid_through with PayPal (lib/subscriptions.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
$r = palette_sub_reconcile($conn);
echo "palette subscriptions: checked {$r['checked']}, changed {$r['changed']}, failed {$r['failed']}\n";
