<?php pageHeader('Sign in'); ?>
<section class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">PRIVATE CV WORKSPACE</p>
        <h1>Sign in</h1>
        <p class="auth-description">Enter the shared app password to open the CV generator, checker, converter, and saved drafts.</p>

        <?php if (!empty($loginError)): ?>
            <div class="auth-error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>?page=login" class="auth-form">
            <input type="hidden" name="action" value="auth_login">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
            <label for="app-password">App password</label>
            <input id="app-password" name="password" type="password" autocomplete="current-password" required maxlength="4096" autofocus>
            <button class="button button-primary" type="submit">Continue</button>
        </form>
    </div>
</section>
<?php pageFooter(); ?>
