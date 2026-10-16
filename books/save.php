<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/books/index.php');
}
require_csrf();
$conn = db();
$rawId = $_POST['book_id'] ?? '';
if (!is_string($rawId)) {
    set_flash('error', 'The book ID is invalid.');
    redirect('/books/index.php');
}
$idRaw = trim($rawId);
$id = $idRaw === '' ? 0 : filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    set_flash('error', 'The book ID is invalid.');
    redirect('/books/index.php');
}
$id = (int) $id;
$values = [
    'book_id' => $id > 0 ? $id : 0,
    'title' => post_string('title'),
    'author' => post_string('author'),
    'isbn' => post_string('isbn'),
    'publisher' => post_string('publisher'),
    'category' => post_string('category'),
    'publication_year' => post_string('publication_year'),
    'total_copies' => post_string('total_copies'),
];
$errors = [];

if ($id < 0) $errors['book_id'] = 'The book ID is invalid.';
if ($values['title'] === '') $errors['title'] = 'Enter a book title.';
elseif (text_length($values['title']) > 200) $errors['title'] = 'The title must be 200 characters or fewer.';
if ($values['author'] === '') $errors['author'] = 'Enter an author.';
elseif (text_length($values['author']) > 150) $errors['author'] = 'The author must be 150 characters or fewer.';
if (text_length($values['isbn']) > 20 || ($values['isbn'] !== '' && !valid_isbn($values['isbn']))) $errors['isbn'] = 'Enter a 10 or 13 character ISBN using digits and, for ISBN-10, an optional final X.';
foreach (['publisher' => 150, 'category' => 80] as $field => $limit) {
    if (text_length($values[$field]) > $limit) $errors[$field] = 'Use ' . $limit . ' characters or fewer.';
}
$copies = filter_var($values['total_copies'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($copies === false) $errors['total_copies'] = 'Enter a whole number between 1 and 65535.';
$year = null;
if ($values['publication_year'] !== '') {
    $parsedYear = filter_var($values['publication_year'], FILTER_VALIDATE_INT);
    if ($parsedYear === false || $parsedYear < 1000 || $parsedYear > (int) date('Y')) $errors['publication_year'] = 'Enter a four-digit year from 1000 through the current year.';
    else $year = $parsedYear;
}

$formUrl = '/books/form.php' . ($id > 0 ? '?id=' . $id : '');
if ($errors) {
    save_form_state('books', $values, $errors);
    redirect($formUrl);
}
$isbn = $values['isbn'] === '' ? null : normalize_isbn($values['isbn']);
$publisher = $values['publisher'] === '' ? null : $values['publisher'];
$category = $values['category'] === '' ? null : $values['category'];

try {
    $conn->begin_transaction();
    if ($id > 0) {
        $lock = $conn->prepare('SELECT book_id FROM books WHERE book_id = ? FOR UPDATE');
        $lock->bind_param('i', $id);
        $lock->execute();
        if (!$lock->get_result()->fetch_assoc()) {
            $conn->rollback();
            set_flash('error', 'That book record no longer exists.');
            redirect('/books/index.php');
        }
        $countStatement = $conn->prepare('SELECT COUNT(*) AS open_count FROM issue_records WHERE book_id = ? AND return_date IS NULL');
        $countStatement->bind_param('i', $id);
        $countStatement->execute();
        $openCount = (int) $countStatement->get_result()->fetch_assoc()['open_count'];
        if ($copies < $openCount) {
            $conn->rollback();
            $errors['total_copies'] = 'This title has ' . $openCount . ' copies on loan. Total copies cannot be lower.';
            save_form_state('books', $values, $errors);
            redirect($formUrl);
        }
    }

    if ($isbn !== null) {
        $duplicateSql = 'SELECT book_id FROM books WHERE isbn = ?' . ($id > 0 ? ' AND book_id <> ?' : '') . ' LIMIT 1';
        $duplicate = $conn->prepare($duplicateSql);
        if ($id > 0) $duplicate->bind_param('si', $isbn, $id);
        else $duplicate->bind_param('s', $isbn);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            $conn->rollback();
            $errors['isbn'] = 'That ISBN is already assigned to another book.';
            save_form_state('books', $values, $errors);
            redirect($formUrl);
        }
    }

    if ($id > 0) {
        $statement = $conn->prepare('UPDATE books SET title = ?, author = ?, isbn = ?, publisher = ?, category = ?, publication_year = ?, total_copies = ? WHERE book_id = ?');
        $statement->bind_param('sssssiii', $values['title'], $values['author'], $isbn, $publisher, $category, $year, $copies, $id);
        $statement->execute();
        $conn->commit();
        set_flash('success', 'Book changes saved.');
    } else {
        $statement = $conn->prepare('INSERT INTO books (title, author, isbn, publisher, category, publication_year, total_copies) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bind_param('sssssii', $values['title'], $values['author'], $isbn, $publisher, $category, $year, $copies);
        $statement->execute();
        $conn->commit();
        set_flash('success', 'Book added to the catalogue.');
    }
    redirect('/books/index.php');
} catch (mysqli_sql_exception $exception) {
    try { $conn->rollback(); } catch (Throwable $ignored) { }
    save_form_state('books', $values, []);
    set_flash('error', 'The book could not be saved. Check that its ISBN is unique and try again.');
    redirect($formUrl);
}
