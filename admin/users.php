<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_role('admin');
$conn = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post_string('action');

    if ($action === 'create') {
        $name = limit_text(post_string('display_name'), 100);
        $email = strtolower(limit_text(post_string('email'), 254));
        $passwordValue = $_POST['password'] ?? '';
        $password = is_string($passwordValue) ? $passwordValue : '';
        if ($name === '') {
            $error = 'Enter a name for the staff account.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (!valid_password($password)) {
            $error = 'Use 8–200 characters with at least one lowercase letter, one uppercase letter and one special character.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'librarian';
                $insert = $conn->prepare('INSERT INTO users (display_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $insert->bind_param('ssss', $name, $email, $hash, $role);
                $insert->execute();
                set_flash('success', 'Staff account created.');
                redirect('/admin/users.php');
            } catch (mysqli_sql_exception $exception) {
                $error = (int) $exception->getCode() === 1062
                    ? 'That email already has an account.'
                    : 'The account could not be created. Check the database and try again.';
            }
        }
    } elseif (in_array($action, ['toggle', 'delete'], true)) {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false || $userId === null) {
            $error = 'Select a valid staff account.';
        } else {
            $conn->begin_transaction();
            try {
                $target = $conn->prepare('SELECT role, is_active FROM users WHERE user_id = ? FOR UPDATE');
                $target->bind_param('i', $userId);
                $target->execute();
                $account = $target->get_result()->fetch_assoc();

                if (!$account) {
                    $error = 'That account could not be found.';
                } elseif ($account['role'] !== 'librarian') {
                    $error = 'Administrator accounts are protected from staff actions.';
                } elseif ($action === 'toggle') {
                    $update = $conn->prepare('UPDATE users SET is_active = 1 - is_active, session_version = session_version + 1 WHERE user_id = ? AND role = \'librarian\'');
                    $update->bind_param('i', $userId);
                    $update->execute();
                    $deleteTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                    $deleteTokens->bind_param('i', $userId);
                    $deleteTokens->execute();
                    $conn->commit();
                    set_flash('success', (bool) $account['is_active'] ? 'Staff account deactivated. Existing sessions were signed out.' : 'Staff account reactivated.');
                    redirect('/admin/users.php');
                } else {
                    $delete = $conn->prepare('DELETE FROM users WHERE user_id = ? AND role = \'librarian\'');
                    $delete->bind_param('i', $userId);
                    $delete->execute();
                    $conn->commit();
                    set_flash('success', 'Staff account permanently removed.');
                    redirect('/admin/users.php');
                }
                $conn->rollback();
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'The staff account could not be updated. Try again.';
            }
        }
    } else {
        $error = 'Choose a valid staff action.';
    }
}

$accounts = $conn->query('SELECT user_id, display_name, email, role, is_active, created_at, last_login_at FROM users ORDER BY role, display_name, user_id');
render_header('Staff access', 'users');
?>
<div class="page-heading"><div><div class="eyebrow">Access control</div><h1>Staff access</h1><p class="page-intro">Create and manage librarian accounts. Administrator accounts are protected and cannot be reset, deactivated or removed here.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel form-panel">
    <div class="panel-heading"><div><div class="eyebrow">New account</div><h2>Add a librarian</h2></div></div>
    <form method="post" action="<?= e(url('/admin/users.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-field"><label for="display_name">Name</label><input id="display_name" name="display_name" maxlength="100" required autocomplete="name"></div>
            <div class="form-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" required autocomplete="email"></div>
            <div class="form-field"><label for="password">Temporary password</label><input id="password" name="password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" data-password-rules aria-describedby="password-rules"><ul class="password-rules" id="password-rules" aria-live="polite"><li data-password-rule="length"><span aria-hidden="true">○</span> At least 8 characters</li><li data-password-rule="lowercase"><span aria-hidden="true">○</span> One lowercase letter</li><li data-password-rule="uppercase"><span aria-hidden="true">○</span> One uppercase letter</li><li data-password-rule="special"><span aria-hidden="true">○</span> One special character</li></ul><small>Share it privately. The librarian can change it from their account.</small></div>
        </div>
        <div class="form-actions"><button class="button button-primary" type="submit">Create librarian account</button></div>
    </form>
</section>
<section class="panel account-list">
    <div class="panel-heading"><div><div class="eyebrow">Accounts</div><h2>Library staff</h2></div><span class="table-meta"><?= number_format($accounts->num_rows) ?> account<?= $accounts->num_rows === 1 ? '' : 's' ?></span></div>
    <div class="table-wrap"><table><thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Last sign in</th><th class="actions-heading">Actions</th></tr></thead><tbody>
    <?php while ($account = $accounts->fetch_assoc()): ?>
        <tr>
            <td><span class="cell-strong"><?= e($account['display_name']) ?></span><span class="cell-sub"><?= e($account['email']) ?></span></td>
            <td><?= e(ucfirst($account['role'])) ?></td>
            <td><span class="account-status <?= (int) $account['is_active'] ? '' : 'is-disabled' ?>"><?= (int) $account['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><?= e($account['last_login_at'] ?: 'Not signed in') ?></td>
            <td class="account-actions">
                <?php if ($account['role'] === 'librarian'): ?>
                    <a class="button button-small button-secondary" href="<?= e(url('/admin/password.php?id=' . (int) $account['user_id'])) ?>">Set password</a>
                    <form method="post" action="<?= e(url('/admin/users.php')) ?>" data-confirm="<?= (int) $account['is_active'] ? 'Deactivate this librarian account?' : 'Reactivate this librarian account?' ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
                        <button class="button button-small <?= (int) $account['is_active'] ? 'button-danger' : 'button-secondary' ?>" type="submit"><?= (int) $account['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/users.php')) ?>" data-confirm="Permanently remove this librarian account? This cannot be undone.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
                        <button class="button button-small button-danger" type="submit">Remove permanently</button>
                    </form>
                <?php else: ?>
                    <span class="account-status">Protected administrator account</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody></table></div>
</section>
<?php render_footer(); ?>
