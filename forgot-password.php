<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/password_reset.php';

if (current_user() !== null) {
    redirect('/index.php');
}

$submitted = false;
$testToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = post_string('email');
    $testToken = request_password_reset($email);
    $submitted = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f5f6">
    <title>Forgot password | LibraryFlow</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="setup-page">
<main class="setup-card">
    <a class="brand auth-brand" href="<?= e(url('/login.php')) ?>"><span class="brand-mark" aria-hidden="true">L</span><span><strong>LibraryFlow</strong><small>Library operations</small></span></a>
    <div class="eyebrow">Account recovery</div>
    <h1>Forgot password?</h1>
    <p class="page-intro">Enter the email address for your staff account. If it matches an active account and email delivery is configured, a reset link will be sent.</p>
    <?php if ($submitted): ?>
        <div class="notice notice-info" role="status">If an active staff account matches that address, a reset link will be sent. Check your inbox. If no message arrives, ask an administrator to set your password.</div>
        <?php if ($testToken !== null && strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'test'): ?><span hidden data-test-reset-token="<?= e($testToken) ?>"></span><?php endif; ?>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/forgot-password.php')) ?>" class="auth-form" autocomplete="on">
        <?= csrf_field() ?>
        <div class="form-field"><label for="email">Staff account email</label><input id="email" name="email" type="email" maxlength="254" required autocomplete="email"></div>
        <button class="button button-primary" type="submit">Send reset link</button>
    </form>
    <p class="auth-help"><a href="<?= e(url('/login.php')) ?>">Back to sign in</a></p>
    <p class="auth-legal"><a href="<?= e(url('/privacy.php')) ?>">Privacy</a> · <a href="<?= e(url('/terms.php')) ?>">Terms</a></p>
</main>
</body>
</html>
