<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$query = query_string('q');
$query = limit_text($query, 100);
$sql = 'SELECT m.member_id, m.member_code, m.full_name, m.course, m.section_group, m.semester, m.phone, m.email,
               (SELECT COUNT(*) FROM issue_records ir WHERE ir.member_id = m.member_id AND ir.return_date IS NULL) AS open_loans,
               (SELECT COUNT(*) FROM issue_records ir WHERE ir.member_id = m.member_id AND ir.return_date IS NULL AND ir.due_date < CURRENT_DATE) AS overdue_open,
               (SELECT MAX(DATE_ADD(ir.return_date, INTERVAL 10 DAY)) FROM issue_records ir WHERE ir.member_id = m.member_id AND ir.return_date IS NOT NULL AND ir.return_date > ir.due_date) AS suspended_until
        FROM members m';
$statement = null;
if ($query !== '') {
    if (ctype_digit($query)) {
        $statement = $conn->prepare($sql . ' WHERE m.member_id = ? OR m.member_code LIKE ? OR m.full_name LIKE ? ORDER BY m.full_name, m.member_id');
        $id = (int) $query;
        $pattern = '%' . $query . '%';
        $statement->bind_param('iss', $id, $pattern, $pattern);
    } else {
        $statement = $conn->prepare($sql . ' WHERE m.member_code LIKE ? OR m.full_name LIKE ? ORDER BY m.full_name, m.member_id');
        $pattern = '%' . $query . '%';
        $statement->bind_param('ss', $pattern, $pattern);
    }
    $statement->execute();
    $members = $statement->get_result();
} else {
    $members = $conn->query($sql . ' ORDER BY m.full_name, m.member_id');
}

render_header('Members', 'members');
?>
<div class="page-heading">
    <div><div class="eyebrow">Member register</div><h1>Members</h1><p class="page-intro">Maintain the member details needed for library lending.</p></div>
    <a class="button button-primary" href="<?= e(url('/members/form.php')) ?>">Add member</a>
</div>
<section class="panel">
    <form class="toolbar-search" method="get" action="<?= e(url('/members/index.php')) ?>">
        <label class="sr-only" for="member-search">Search by member code, name or ID</label>
        <input id="member-search" type="search" name="q" value="<?= e($query) ?>" placeholder="Search member name, code or ID">
        <button class="button button-secondary" type="submit">Search</button>
        <?php if ($query !== ''): ?><a class="text-link" href="<?= e(url('/members/index.php')) ?>">Clear search</a><?php endif; ?>
    </form>
    <div class="table-meta"><span><?= number_format($members->num_rows) ?> record<?= $members->num_rows === 1 ? '' : 's' ?></span><?php if ($query !== ''): ?><span>Matching “<?= e($query) ?>”</span><?php endif; ?></div>
    <?php if ($members->num_rows === 0): ?>
        <div class="empty-state"><h2><?= $query === '' ? 'No members registered' : 'No matching members' ?></h2><p><?= $query === '' ? 'Add a member before creating a loan.' : 'Try another name, member code or ID.' ?></p><?php if ($query === ''): ?><a class="button button-primary" href="<?= e(url('/members/form.php')) ?>">Add a member</a><?php endif; ?></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Member</th><th>Course</th><th>Semester</th><th>Phone</th><th>Email</th><th>Open loans</th><th>Borrowing status</th><th class="actions-heading">Actions</th></tr></thead>
            <tbody>
            <?php while ($member = $members->fetch_assoc()): ?>
                <tr>
                    <td><span class="cell-strong"><?= e($member['full_name']) ?></span><span class="cell-sub"><?= e($member['member_code']) ?> · ID <?= (int) $member['member_id'] ?></span></td>
                    <td><?= e($member['course'] ?: 'Not supplied') ?><?php if (!empty($member['section_group'])): ?><span class="cell-sub"><?= e($member['section_group']) ?></span><?php endif; ?></td>
                    <td><?= $member['semester'] === null ? 'Not supplied' : (int) $member['semester'] ?></td>
                    <td><?= e($member['phone'] ?: 'Not supplied') ?></td>
                    <td><?= e($member['email'] ?: 'Not supplied') ?></td>
                    <td><?= (int) $member['open_loans'] ?></td>
                    <td><?php if ((int) $member['overdue_open'] > 0): ?><span class="account-status is-disabled">Overdue loan outstanding</span><?php elseif ($member['suspended_until'] !== null && $member['suspended_until'] > date('Y-m-d')): ?><span class="account-status is-disabled">Borrowing hold</span><span class="cell-sub">Until <?= e(display_date($member['suspended_until'])) ?></span><?php else: ?>Eligible to borrow<?php endif; ?></td>
                    <td class="actions-cell"><div class="row-actions"><a href="<?= e(url('/members/form.php?id=' . (int) $member['member_id'])) ?>">Edit</a><a class="danger-link" href="<?= e(url('/members/delete.php?id=' . (int) $member['member_id'])) ?>">Delete</a></div></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
