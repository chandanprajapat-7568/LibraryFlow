<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_role('admin');
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        set_flash('error', 'Select a valid member record.');
        redirect('/members/index.php');
    }
    $id = (int) $id;
    try {
        $statement = $conn->prepare('DELETE FROM members WHERE member_id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
        set_flash($statement->affected_rows ? 'success' : 'error', $statement->affected_rows ? 'Member deleted.' : 'That member record could not be found.');
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1451) set_flash('error', 'This member has issue history and cannot be deleted. Keep the member record so that history remains intact.');
        else set_flash('error', 'The member could not be deleted. Try again.');
    }
    redirect('/members/index.php');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($id < 1) {
    set_flash('error', 'Select a valid member record.');
    redirect('/members/index.php');
}
$statement = $conn->prepare('SELECT member_id, member_code, full_name FROM members WHERE member_id = ?');
$statement->bind_param('i', $id);
$statement->execute();
$member = $statement->get_result()->fetch_assoc();
if (!$member) {
    set_flash('error', 'That member record could not be found.');
    redirect('/members/index.php');
}
$history = $conn->prepare('SELECT COUNT(*) AS total FROM issue_records WHERE member_id = ?');
$history->bind_param('i', $id);
$history->execute();
$hasHistory = (int) $history->get_result()->fetch_assoc()['total'] > 0;
render_header('Delete member', 'members');
?>
<div class="page-heading"><div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/members/index.php')) ?>">Members</a> / Delete</div><h1>Delete member record</h1><p class="page-intro">Review the selected record before confirming this action.</p></div></div>
<section class="panel confirm-panel">
    <div class="eyebrow">Member code <?= e($member['member_code']) ?> · ID <?= (int) $member['member_id'] ?></div>
    <h2><?= e($member['full_name']) ?></h2>
    <?php if ($hasHistory): ?>
        <div class="notice notice-error">This member has lending history. The record cannot be deleted because issue records must be preserved.</div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/members/index.php')) ?>">Back to members</a><a class="button button-primary" href="<?= e(url('/members/form.php?id=' . $id)) ?>">Edit member</a></div>
    <?php else: ?>
        <p>This removes the member record permanently. This action cannot be undone.</p>
        <form method="post" action="<?= e(url('/members/delete.php')) ?>" data-confirm="Delete this member record?">
            <?= csrf_field() ?><input type="hidden" name="member_id" value="<?= $id ?>">
            <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/members/index.php')) ?>">Cancel</a><button class="button button-danger" type="submit">Delete member</button></div>
        </form>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
