<?php
// Compatibility redirect for the removed toolbox login/logout flow.
require_once __DIR__ . '/bootstrap.php';
header('Location: ' . BASE_URL, true, 302);
exit;
