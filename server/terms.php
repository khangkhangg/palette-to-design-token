<?php
// /palette/terms: terms of sale and use. Markup in lib/pages.php.
require_once __DIR__ . '/bootstrap.php';
palette_send_page_headers();
echo palette_render_terms(palette_page_ctx());
