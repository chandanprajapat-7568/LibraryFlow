<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        set_flash('error', 'Select a valid book record.');
        redirect('/books/index.php');
    }
    $id = (int) $id;
    try {
        $statement = $conn->prepare('DELETE FROM books WHERE book_id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
        set_flash($statement->affected_rows ? 'success' : 'error', $statement->affected_rows ? 'Book deleted.' : 'That book record could not be found.');
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1451) set_flash('error', 'This book has issue history and cannot be deleted. Keep it so the lending history remains intact.');
        else set_flash('error', 'The book could not be deleted. Try again.');
    }
    redirect('/books/index.php');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($id < 1) {
    set_flash('error', 'Select a valid book record.');
    redirect('/books/index.php');
}
$statement = $conn->prepare('SELECT book_id, title, author FROM books WHERE book_id = ?');
$statement->bind_param('i', $id);
$statement->execute();
$book = $statement->get_result()->fetch_assoc();
if (!$book) {
    set_flash('error', 'That book record could not be found.');
    redirect('/books/index.php');
}
$history = $conn->prepare('SELECT COUNT(*) AS total FROM issue_records WHERE book_id = ?');
$history->bind_param('i', $id);
$history->execute();
$hasHistory = (int) $history->get_result()->fetch_assoc()['total'] > 0;
render_header('Delete book', 'books');
?>
<div class="page-heading"><div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/books/index.php')) ?>">Books</a> / Delete</div><h1>Delete book record</h1><p class="page-intro">Review the selected record before confirming this action.</p></div></div>
<section class="panel confirm-panel">
    <div class="eyebrow">Book ID <?= (int) $book['book_id'] ?></div>
    <h2><?= e($book['title']) ?></h2><p class="muted">By <?= e($book['author']) ?></p>
    <?php if ($hasHistory): ?>
        <div class="notice notice-error">This book has lending history. It cannot be deleted because issue records must be preserved.</div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/books/index.php')) ?>">Back to books</a><a class="button button-primary" href="<?= e(url('/books/form.php?id=' . $id)) ?>">Edit book</a></div>
    <?php else: ?>
        <p>This removes the book record permanently. This action cannot be undone.</p>
        <form method="post" action="<?= e(url('/books/delete.php')) ?>" data-confirm="Delete this book record?">
            <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= $id ?>">
            <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/books/index.php')) ?>">Cancel</a><button class="button button-danger" type="submit">Delete book</button></div>
        </form>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
