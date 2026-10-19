<?php
// Keep the legacy GET URL non-mutating. The app's sign-out form uses a
// CSRF-protected POST request handled by index.php.
require_once __DIR__ . '/bootstrap.php';
header('Location: ' . BASE_URL, true, 302);
exit;
