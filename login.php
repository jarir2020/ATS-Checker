<?php
// Compatibility redirect for bookmarks from the previous toolbox login screen.
require_once __DIR__ . '/bootstrap.php';
header('Location: ' . BASE_URL, true, 302);
exit;
