<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$userId = (int) current_user()['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $currentValue = $_POST['current_password'] ?? '';
    $newValue = $_POST['new_password'] ?? '';
    $confirmValue = $_POST['confirm_password'] ?? '';
    $currentPassword = is_string($currentValue) ? $currentValue : '';
    $newPassword = is_string($newValue) ? $newValue : '';
    $confirmPassword = is_string($confirmValue) ? $confirmValue : '';

    $lookup = $conn->prepare('SELECT password_hash, session_version FROM users WHERE user_id = ? AND is_active = 1');
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $account = $lookup->get_result()->fetch_assoc();
    if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
        $error = 'The current password is incorrect.';
    } elseif (!valid_password($newPassword)) {
        $error = 'Use 8–200 characters with at least one lowercase letter, one uppercase letter and one special character.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $error = 'The new passwords do not match.';
    } elseif (hash_equals($currentPassword, $newPassword)) {
        $error = 'Choose a new password that differs from the current one.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $update = $conn->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE user_id = ? AND is_active = 1');
            $update->bind_param('si', $hash, $userId);
            $update->execute();
            $deleteTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            $deleteTokens->bind_param('i', $userId);
            $deleteTokens->execute();
            $conn->commit();
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['auth_version'] = (int) $account['session_version'] + 1;
            $_SESSION['last_activity'] = time();
            set_flash('success', 'Your password has been changed.');
            redirect('/account/password.php');
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = 'The password could not be changed. Try again.';
        }
    }
}

render_header('Change password', 'password');
?>
<div class="page-heading"><div><div class="eyebrow">Account security</div><h1>Change password</h1><p class="page-intro">Use at least 8 characters, including a lowercase letter, an uppercase letter and a special character.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel form-panel">
    <form method="post" action="<?= e(url('/account/password.php')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
            <div class="form-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" data-password-rules aria-describedby="new-password-rules"><ul class="password-rules" id="new-password-rules" aria-live="polite"><li data-password-rule="length"><span aria-hidden="true">○</span> At least 8 characters</li><li data-password-rule="lowercase"><span aria-hidden="true">○</span> One lowercase letter</li><li data-password-rule="uppercase"><span aria-hidden="true">○</span> One uppercase letter</li><li data-password-rule="special"><span aria-hidden="true">○</span> One special character</li></ul></div>
            <div class="form-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"></div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/index.php')) ?>">Cancel</a><button class="button button-primary" type="submit">Update password</button></div>
    </form>
</section>
<?php render_footer(); ?>
