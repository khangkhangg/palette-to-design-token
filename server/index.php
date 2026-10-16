<?php
// /palette/ : the product landing page. Markup in lib/pages.php.
require_once __DIR__ . '/bootstrap.php';
palette_send_page_headers();
echo palette_render_landing(palette_page_ctx());
