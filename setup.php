<?php
require __DIR__ . '/includes/bootstrap.php';

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (getenv('APP_ENV') === 'production' || !in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit('Not found.');
}

$conn = db();
$userCount = (int) $conn->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
if ($userCount > 0) {
    redirect('/admin-login.php');
}

$displayName = '';
$email = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $displayName = limit_text(post_string('display_name'), 100);
    $email = mb_strtolower(limit_text(post_string('email'), 254), 'UTF-8');
    $passwordValue = $_POST['password'] ?? '';
    $confirmValue = $_POST['confirm_password'] ?? '';
    $password = is_string($passwordValue) ? $passwordValue : '';
    $confirmPassword = is_string($confirmValue) ? $confirmValue : '';
    if ($displayName === '' || text_length($displayName) > 100) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!valid_password($password)) {
        $error = 'Use 8–200 characters with at least one lowercase letter, one uppercase letter and one special character.';
    } elseif (!hash_equals($password, $confirmPassword)) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO users (display_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $role = 'admin';
            $insert->bind_param('ssss', $displayName, $email, $hash, $role);
            $insert->execute();
            set_flash('success', 'Administrator created. Sign in with the email and password you chose.');
            redirect('/admin-login.php');
        } catch (mysqli_sql_exception $exception) {
            $error = (int) $exception->getCode() === 1062
                ? 'That email is already in use.'
                : 'The administrator account could not be created. Check the database setup and try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f5f6">
    <title>First administrator setup | LibraryFlow</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="setup-page">
<main class="setup-card">
    <a class="brand auth-brand" href="<?= e(url('/admin-login.php')) ?>"><span class="brand-mark" aria-hidden="true">L</span><span><strong>LibraryFlow</strong><small>Library operations</small></span></a>
    <div class="eyebrow">One-time setup</div>
    <h1>Create the first administrator</h1>
    <p class="page-intro">This setup works only from this computer and only while no staff account exists. Use at least 8 characters, including a lowercase letter, an uppercase letter and a special character.</p>
    <?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/setup.php')) ?>" class="auth-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-field"><label for="display_name">Name</label><input id="display_name" name="display_name" maxlength="100" required autocomplete="name" value="<?= e($displayName) ?>"></div>
        <div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" required autocomplete="email" value="<?= e($email) ?>"></div>
        <div class="form-field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" data-password-rules aria-describedby="password-rules"><ul class="password-rules" id="password-rules" aria-live="polite"><li data-password-rule="length"><span aria-hidden="true">○</span> At least 8 characters</li><li data-password-rule="lowercase"><span aria-hidden="true">○</span> One lowercase letter</li><li data-password-rule="uppercase"><span aria-hidden="true">○</span> One uppercase letter</li><li data-password-rule="special"><span aria-hidden="true">○</span> One special character</li></ul></div>
        <div class="form-field"><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"></div>
        <button class="button button-primary" type="submit">Create administrator</button>
    </form>
    <p class="auth-legal"><a href="<?= e(url('/admin-login.php')) ?>">Back to administrator sign in</a></p>
</main>
</body>
</html>
