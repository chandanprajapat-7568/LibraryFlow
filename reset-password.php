<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/password_reset.php';

if (current_user() !== null) {
    redirect('/index.php');
}

$token = query_string('token');
$error = '';
$complete = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $tokenValue = $_POST['token'] ?? '';
    $token = is_string($tokenValue) ? $tokenValue : '';
    $passwordValue = $_POST['new_password'] ?? '';
    $confirmValue = $_POST['confirm_password'] ?? '';
    $password = is_string($passwordValue) ? $passwordValue : '';
    $confirmPassword = is_string($confirmValue) ? $confirmValue : '';
    if (valid_password_reset_token($token) === null) {
        $error = 'This reset link is invalid or has expired. Request a new link or ask an administrator for help.';
    } elseif (!valid_password($password)) {
        $error = 'Use 8–200 characters with at least one lowercase letter, one uppercase letter and one special character.';
    } elseif (!hash_equals($password, $confirmPassword)) {
        $error = 'The passwords do not match.';
    } elseif (complete_password_reset($token, $password)) {
        $complete = true;
    } else {
        $error = 'This reset link is invalid or has expired. Request a new link or ask an administrator for help.';
    }
}

$tokenIsValid = !$complete && valid_password_reset_token($token) !== null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f5f6">
    <title>Set a new password | LibraryFlow</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="setup-page">
<main class="setup-card">
    <a class="brand auth-brand" href="<?= e(url('/login.php')) ?>"><span class="brand-mark" aria-hidden="true">L</span><span><strong>LibraryFlow</strong><small>Library operations</small></span></a>
    <div class="eyebrow">Account recovery</div>
    <?php if ($complete): ?>
        <h1>Password updated</h1>
        <div class="notice notice-success" role="status">Your password has been changed. Other active sessions have been signed out.</div>
        <a class="button button-primary" href="<?= e(url('/login.php')) ?>">Continue to sign in</a>
    <?php elseif ($tokenIsValid): ?>
        <h1>Set a new password</h1>
        <p class="page-intro">Choose at least 8 characters with a lowercase letter, an uppercase letter and a special character. This link can only be used once and expires after one hour.</p>
        <?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/reset-password.php')) ?>" class="auth-form" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="form-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" data-password-rules aria-describedby="new-password-rules"><ul class="password-rules" id="new-password-rules" aria-live="polite"><li data-password-rule="length"><span aria-hidden="true">○</span> At least 8 characters</li><li data-password-rule="lowercase"><span aria-hidden="true">○</span> One lowercase letter</li><li data-password-rule="uppercase"><span aria-hidden="true">○</span> One uppercase letter</li><li data-password-rule="special"><span aria-hidden="true">○</span> One special character</li></ul></div>
            <div class="form-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"></div>
            <button class="button button-primary" type="submit">Save new password</button>
        </form>
    <?php else: ?>
        <h1>Reset link unavailable</h1>
        <div class="notice notice-error" role="alert"><?= e($error !== '' ? $error : 'This reset link is invalid or has expired. Request a new link or ask an administrator for help.') ?></div>
        <p class="auth-help"><a href="<?= e(url('/forgot-password.php')) ?>">Request another reset link</a></p>
    <?php endif; ?>
    <p class="auth-legal"><a href="<?= e(url('/privacy.php')) ?>">Privacy</a> · <a href="<?= e(url('/terms.php')) ?>">Terms</a></p>
</main>
</body>
</html>
