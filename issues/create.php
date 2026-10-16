<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $values = [
        'book_id' => post_string('book_id'),
        'member_id' => post_string('member_id'),
        'issue_date' => post_string('issue_date'),
        'due_date' => post_string('due_date'),
    ];
    $errors = [];
    $bookId = filter_var($values['book_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $memberId = filter_var($values['member_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($bookId === false) $errors['book_id'] = 'Select a book.';
    if ($memberId === false) $errors['member_id'] = 'Select a member.';
    if (!valid_iso_date($values['issue_date'])) $errors['issue_date'] = 'Enter a valid issue date.';
    if (!valid_iso_date($values['due_date'])) $errors['due_date'] = 'Enter a valid due date.';
    if (empty($errors['issue_date']) && empty($errors['due_date']) && $values['due_date'] < $values['issue_date']) $errors['due_date'] = 'Due date must be on or after the issue date.';

    if ($errors) {
        save_form_state('issue_create', $values, $errors);
        redirect('/issues/create.php');
    }

    try {
        $conn->begin_transaction();
        $bookLock = $conn->prepare('SELECT total_copies FROM books WHERE book_id = ? FOR UPDATE');
        $bookLock->bind_param('i', $bookId);
        $bookLock->execute();
        $book = $bookLock->get_result()->fetch_assoc();
        if (!$book) {
            $conn->rollback();
            save_form_state('issue_create', $values, ['book_id' => 'That book is no longer available in the catalogue.']);
            redirect('/issues/create.php');
        }
        $memberCheck = $conn->prepare('SELECT member_id FROM members WHERE member_id = ? FOR UPDATE');
        $memberCheck->bind_param('i', $memberId);
        $memberCheck->execute();
        if (!$memberCheck->get_result()->fetch_assoc()) {
            $conn->rollback();
            save_form_state('issue_create', $values, ['member_id' => 'That member is no longer in the register.']);
            redirect('/issues/create.php');
        }
        $overdueCheck = $conn->prepare('SELECT COUNT(*) AS overdue_count FROM issue_records WHERE member_id = ? AND return_date IS NULL AND due_date < CURRENT_DATE');
        $overdueCheck->bind_param('i', $memberId);
        $overdueCheck->execute();
        if ((int) $overdueCheck->get_result()->fetch_assoc()['overdue_count'] > 0) {
            $conn->rollback();
            save_form_state('issue_create', $values, ['member_id' => 'Return the overdue book before issuing another title.']);
            redirect('/issues/create.php');
        }
        $suspensionCheck = $conn->prepare('SELECT MAX(DATE_ADD(return_date, INTERVAL 10 DAY)) AS suspended_until FROM issue_records WHERE member_id = ? AND return_date IS NOT NULL AND return_date > due_date HAVING suspended_until > CURRENT_DATE');
        $suspensionCheck->bind_param('i', $memberId);
        $suspensionCheck->execute();
        $suspension = $suspensionCheck->get_result()->fetch_assoc();
        if ($suspension && $suspension['suspended_until'] !== null) {
            $conn->rollback();
            save_form_state('issue_create', $values, ['member_id' => 'Borrowing is suspended until ' . display_date($suspension['suspended_until']) . ' because a book was returned late.']);
            redirect('/issues/create.php');
        }
        $openCount = $conn->prepare('SELECT COUNT(*) AS open_count FROM issue_records WHERE book_id = ? AND return_date IS NULL');
        $openCount->bind_param('i', $bookId);
        $openCount->execute();
        $issued = (int) $openCount->get_result()->fetch_assoc()['open_count'];
        if ($issued >= (int) $book['total_copies']) {
            $conn->rollback();
            save_form_state('issue_create', $values, ['book_id' => 'No copies of this title are currently available.']);
            redirect('/issues/create.php');
        }
        $insert = $conn->prepare('INSERT INTO issue_records (book_id, member_id, issue_date, due_date) VALUES (?, ?, ?, ?)');
        $insert->bind_param('iiss', $bookId, $memberId, $values['issue_date'], $values['due_date']);
        $insert->execute();
        $conn->commit();
        set_flash('success', 'Book issued. The availability count has been updated.');
        redirect('/issues/index.php');
    } catch (mysqli_sql_exception $exception) {
        try { $conn->rollback(); } catch (Throwable $ignored) { }
        save_form_state('issue_create', $values, []);
        set_flash('error', 'The issue could not be saved. Refresh the page and confirm that the selected book and member still exist.');
        redirect('/issues/create.php');
    }
}

$state = consume_form_state('issue_create');
$values = array_merge([
    'book_id' => '',
    'member_id' => '',
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')),
], $state['values'] ?? []);
$errors = $state['errors'] ?? [];
$books = $conn->query(
    'SELECT b.book_id, b.title, b.author, b.total_copies,
            (b.total_copies - (SELECT COUNT(*) FROM issue_records ir WHERE ir.book_id = b.book_id AND ir.return_date IS NULL)) AS available_copies
     FROM books b
     HAVING available_copies > 0
     ORDER BY b.title, b.book_id'
);
$members = $conn->query('SELECT member_id, member_code, full_name FROM members ORDER BY full_name, member_id');
render_header('Issue a book', 'issues');
?>
<div class="page-heading">
    <div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/issues/index.php')) ?>">Issue / return</a> / New issue</div><h1>Issue a book</h1><p class="page-intro">Only titles with an available copy can be issued. Members with an overdue loan or an active 10-day late-return hold cannot borrow.</p></div>
</div>
<section class="panel form-panel">
    <?php if ($books->num_rows === 0 || $members->num_rows === 0): ?>
        <div class="empty-state"><h2><?= $books->num_rows === 0 ? 'No available books' : 'No members registered' ?></h2><p><?= $books->num_rows === 0 ? 'Add a title with copies available before creating an issue.' : 'Register a member before creating an issue.' ?></p><div class="heading-actions"><a class="button button-secondary" href="<?= e(url('/books/form.php')) ?>">Add book</a><a class="button button-primary" href="<?= e(url('/members/form.php')) ?>">Add member</a></div></div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/issues/create.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-field <?= isset($errors['book_id']) ? 'has-error' : '' ?>"><label for="book_id">Book <span class="required-mark">Required</span></label><select id="book_id" name="book_id" required aria-describedby="book_id-error"><option value="">Choose a book</option><?php while ($book = $books->fetch_assoc()): ?><option value="<?= (int) $book['book_id'] ?>" <?= (string) $values['book_id'] === (string) $book['book_id'] ? 'selected' : '' ?>><?= e($book['title']) ?> · <?= (int) $book['available_copies'] ?> available</option><?php endwhile; ?></select><?= field_error($errors, 'book_id') ?></div>
                <div class="form-field <?= isset($errors['member_id']) ? 'has-error' : '' ?>"><label for="member_id">Member <span class="required-mark">Required</span></label><select id="member_id" name="member_id" required aria-describedby="member_id-error"><option value="">Choose a member</option><?php while ($member = $members->fetch_assoc()): ?><option value="<?= (int) $member['member_id'] ?>" <?= (string) $values['member_id'] === (string) $member['member_id'] ? 'selected' : '' ?>><?= e($member['full_name']) ?> · <?= e($member['member_code']) ?></option><?php endwhile; ?></select><?= field_error($errors, 'member_id') ?></div>
                <div class="form-field <?= isset($errors['issue_date']) ? 'has-error' : '' ?>"><label for="issue_date">Issue date <span class="required-mark">Required</span></label><input id="issue_date" name="issue_date" type="date" required value="<?= e($values['issue_date']) ?>" aria-describedby="issue_date-error"><?= field_error($errors, 'issue_date') ?></div>
                <div class="form-field <?= isset($errors['due_date']) ? 'has-error' : '' ?>"><label for="due_date">Due date <span class="required-mark">Required</span></label><input id="due_date" name="due_date" type="date" required value="<?= e($values['due_date']) ?>" aria-describedby="due_date-error"><small>Default loan period is 14 calendar days.</small><?= field_error($errors, 'due_date') ?></div>
            </div>
            <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/issues/index.php')) ?>">Cancel</a><button class="button button-primary" type="submit">Confirm issue</button></div>
        </form>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
