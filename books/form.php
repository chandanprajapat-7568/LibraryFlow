<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$book = ['book_id' => 0, 'title' => '', 'author' => '', 'isbn' => '', 'publisher' => '', 'category' => '', 'publication_year' => '', 'total_copies' => '1'];

if ($id > 0) {
    $statement = $conn->prepare('SELECT book_id, title, author, isbn, publisher, category, publication_year, total_copies FROM books WHERE book_id = ?');
    $statement->bind_param('i', $id);
    $statement->execute();
    $savedBook = $statement->get_result()->fetch_assoc();
    if (!$savedBook) {
        set_flash('error', 'That book record could not be found.');
        redirect('/books/index.php');
    }
    $book = $savedBook;
}

$state = consume_form_state('books');
$book = array_merge($book, $state['values'] ?? []);
$errors = $state['errors'] ?? [];
$editing = (int) $book['book_id'] > 0;
render_header($editing ? 'Edit book' : 'Add book', 'books');
?>
<div class="page-heading">
    <div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/books/index.php')) ?>">Books</a> / <?= $editing ? 'Edit record' : 'New record' ?></div><h1><?= $editing ? 'Edit book' : 'Add a book' ?></h1><p class="page-intro">Enter catalogue details and the number of physical copies.</p></div>
</div>
<section class="panel form-panel">
    <form method="post" action="<?= e(url('/books/save.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="book_id" value="<?= (int) $book['book_id'] ?>">
        <div class="form-grid">
            <div class="form-field <?= isset($errors['title']) ? 'has-error' : '' ?>"><label for="title">Title <span class="required-mark">Required</span></label><input id="title" name="title" maxlength="200" required value="<?= e($book['title']) ?>" aria-describedby="title-error"><small>Up to 200 characters.</small><?= field_error($errors, 'title') ?></div>
            <div class="form-field <?= isset($errors['author']) ? 'has-error' : '' ?>"><label for="author">Author <span class="required-mark">Required</span></label><input id="author" name="author" maxlength="150" required value="<?= e($book['author']) ?>" aria-describedby="author-error"><small>Up to 150 characters.</small><?= field_error($errors, 'author') ?></div>
            <div class="form-field <?= isset($errors['isbn']) ? 'has-error' : '' ?>"><label for="isbn">ISBN</label><input id="isbn" name="isbn" maxlength="20" value="<?= e($book['isbn']) ?>" aria-describedby="isbn-error"><small>Optional. ISBN must be unique when supplied.</small><?= field_error($errors, 'isbn') ?></div>
            <div class="form-field <?= isset($errors['total_copies']) ? 'has-error' : '' ?>"><label for="total_copies">Total copies <span class="required-mark">Required</span></label><input id="total_copies" name="total_copies" type="number" min="1" max="65535" step="1" required value="<?= e($book['total_copies']) ?>" aria-describedby="total_copies-error"><small>Cannot be lower than copies currently on loan.</small><?= field_error($errors, 'total_copies') ?></div>
            <div class="form-field <?= isset($errors['publisher']) ? 'has-error' : '' ?>"><label for="publisher">Publisher</label><input id="publisher" name="publisher" maxlength="150" value="<?= e($book['publisher']) ?>" aria-describedby="publisher-error"><?= field_error($errors, 'publisher') ?></div>
            <div class="form-field <?= isset($errors['category']) ? 'has-error' : '' ?>"><label for="category">Category</label><input id="category" name="category" maxlength="80" value="<?= e($book['category']) ?>" aria-describedby="category-error"><?= field_error($errors, 'category') ?></div>
            <div class="form-field <?= isset($errors['publication_year']) ? 'has-error' : '' ?>"><label for="publication_year">Publication year</label><input id="publication_year" name="publication_year" type="number" min="1000" max="<?= (int) date('Y') ?>" step="1" value="<?= e($book['publication_year']) ?>" aria-describedby="publication_year-error"><small>Optional, through <?= (int) date('Y') ?>.</small><?= field_error($errors, 'publication_year') ?></div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/books/index.php')) ?>">Cancel</a><button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Save book' ?></button></div>
    </form>
</section>
<?php render_footer(); ?>
