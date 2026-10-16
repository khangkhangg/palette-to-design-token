<?php
// /palette/account: a signed-in vietnamcos user's keys, and a form to add one (claim).
require_once __DIR__ . '/bootstrap.php';
palette_send_page_headers(['Cache-Control' => 'private, no-store']);
echo palette_render_account(palette_page_ctx());
