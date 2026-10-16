<?php
// /palette/checkout: buy Pro with PayPal. Markup in lib/pages.php, behaviour in assets/checkout.js.
require_once __DIR__ . '/bootstrap.php';
palette_send_page_headers();
echo palette_render_checkout(palette_page_ctx());
