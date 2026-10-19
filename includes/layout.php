<?php
// 2026-05-08
function pageHeader(string $title, bool $showBack = false): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ats.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/vault.css">
</head>
<body>
<nav class="navbar">
    <a href="<?= BASE_URL ?>" class="brand"><span aria-hidden="true">▤</span> <?= htmlspecialchars(APP_NAME) ?></a>
    <?php if (authIsAuthenticated()): ?>
    <div class="nav-actions product-nav">
        <a href="<?= BASE_URL ?>?page=generator" class="nav-link">Generator</a>
        <a href="<?= BASE_URL ?>?page=checker" class="nav-link">Checker</a>
        <a href="<?= BASE_URL ?>?page=converter" class="nav-link">Converter</a>
        <form class="nav-logout" method="post" action="<?= BASE_URL ?>">
            <input type="hidden" name="action" value="auth_logout">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
            <button type="submit">Sign out</button>
        </form>
    </div>
    <?php endif; ?>
</nav>
<main class="main">
<?php }

function pageFooter(): void { ?>
</main>
<footer class="footer">
    <p><?= htmlspecialchars(APP_NAME) ?> &copy; <?= date('Y') ?></p>
</footer>
</body>
</html>
<?php }
