<?php
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
header('Location: ' . BASE_URL . '?page=generator', true, 302);
exit;
