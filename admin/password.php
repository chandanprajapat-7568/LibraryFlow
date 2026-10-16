<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_role('admin');
$conn = db();
$userId = filter_var($_GET['id'] ?? $_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false || $userId === null) {
    http_response_code(404);
    exit('Staff account not found.');
}
$userId = (int) $userId;
$lookup = $conn->prepare("SELECT user_id, display_name, email FROM users WHERE user_id = ? AND role = 'librarian'");
$lookup->bind_param('i', $userId);
$lookup->execute();
$staff = $lookup->get_result()->fetch_assoc();
if (!$staff) {
    http_response_code(404);
    exit('Staff account not found.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $passwordValue = $_POST['new_password'] ?? '';
    $confirmValue = $_POST['confirm_password'] ?? '';
    $password = is_string($passwordValue) ? $passwordValue : '';
    $confirmPassword = is_string($confirmValue) ? $confirmValue : '';

    if (!valid_password($password)) {
        $error = 'Use 8–200 characters with at least one lowercase letter, one uppercase letter and one special character.';
    } elseif (!hash_equals($password, $confirmPassword)) {
        $error = 'The passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $update = $conn->prepare("UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE user_id = ? AND role = 'librarian'");
            $update->bind_param('si', $hash, $userId);
            $update->execute();
            $deleteTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            $deleteTokens->bind_param('i', $userId);
            $deleteTokens->execute();
            $conn->commit();
            set_flash('success', 'Password updated. The librarian’s existing sessions were signed out.');
            redirect('/admin/users.php');
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = 'The password could not be updated. Try again.';
        }
    }
}

render_header('Set staff password', 'users');
?>
<div class="page-heading"><div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/admin/users.php')) ?>">Staff access</a> / Password</div><h1>Set a staff password</h1><p class="page-intro">Set a new password for <?= e($staff['display_name']) ?>. They will need to sign in again.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel form-panel">
    <div class="panel-heading"><div><div class="eyebrow">Librarian account</div><h2><?= e($staff['display_name']) ?></h2><span class="cell-sub"><?= e($staff['email']) ?></span></div></div>
    <form method="post" action="<?= e(url('/admin/password.php?id=' . $userId)) ?>" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $userId ?>">
        <div class="form-grid">
            <div class="form-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" data-password-rules aria-describedby="new-password-rules"><ul class="password-rules" id="new-password-rules" aria-live="polite"><li data-password-rule="length"><span aria-hidden="true">○</span> At least 8 characters</li><li data-password-rule="lowercase"><span aria-hidden="true">○</span> One lowercase letter</li><li data-password-rule="uppercase"><span aria-hidden="true">○</span> One uppercase letter</li><li data-password-rule="special"><span aria-hidden="true">○</span> One special character</li></ul></div>
            <div class="form-field"><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"></div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/admin/users.php')) ?>">Cancel</a><button class="button button-primary" type="submit">Save password</button></div>
    </form>
</section>
<?php render_footer(); ?>
