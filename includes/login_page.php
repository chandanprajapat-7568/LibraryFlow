<?php
if (current_user() !== null) {
    redirect('/index.php');
}

$email = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = limit_text(post_string('email'), 254);
    $passwordValue = $_POST['password'] ?? '';
    $password = is_string($passwordValue) ? $passwordValue : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email and password.';
    } else {
        $result = attempt_login($email, $password, $loginRole);
        if ($result['ok']) {
            set_flash('success', 'You are signed in.');
            redirect('/index.php');
        }
        $error = $result['message'];
    }
}

$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
$flashMessage = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f5f6">
    <title><?= e($loginLabel) ?> | LibraryFlow</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="setup-page">
<main class="setup-card">
    <a class="brand auth-brand" href="<?= e(url($loginRoute)) ?>"><span class="brand-mark" aria-hidden="true">L</span><span><strong>LibraryFlow</strong><small>Library operations</small></span></a>
    <div class="eyebrow"><?= e($loginLabel) ?></div>
    <h1>Sign in</h1>
    <p class="page-intro"><?= e($loginDescription) ?></p>
    <?php if ($flashMessage): ?><div class="notice notice-<?= e($flashMessage['type'] ?? 'info') ?>" role="status"><?= e($flashMessage['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url($loginRoute)) ?>" class="auth-form" autocomplete="on">
        <?= csrf_field() ?>
        <div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" required autocomplete="username" value="<?= e($email) ?>"></div>
        <div class="form-field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
        <button class="button button-primary" type="submit">Sign in</button>
    </form>
    <p class="auth-help"><a href="<?= e(url('/forgot-password.php')) ?>">Forgot password?</a></p>
    <p class="auth-help"><a href="<?= e(url($alternateLoginRoute)) ?>"><?= e($alternateLoginLabel) ?></a></p>
    <?php if ($loginRole === 'librarian' && $userCount === 0 && getenv('APP_ENV') !== 'production'): ?>
        <p class="auth-help">No staff accounts exist yet. <a href="<?= e(url('/setup.php')) ?>">Create the first administrator</a> from this computer.</p>
    <?php endif; ?>
    <p class="auth-legal"><a href="<?= e(url('/privacy.php')) ?>">Privacy</a> · <a href="<?= e(url('/terms.php')) ?>">Terms</a></p>
</main>
</body>
</html>
