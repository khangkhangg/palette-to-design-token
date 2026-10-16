<?php
// /palette/privacy: the extension's privacy policy in 7 languages.
require_once __DIR__ . '/bootstrap.php';
palette_send_page_headers();
echo palette_render_privacy(palette_page_ctx());
