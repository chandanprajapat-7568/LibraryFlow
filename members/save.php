<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/members/index.php');
require_csrf();
$conn = db();
$rawId = $_POST['member_id'] ?? '';
if (!is_string($rawId)) {
    set_flash('error', 'The member ID is invalid.');
    redirect('/members/index.php');
}
$idRaw = trim($rawId);
$id = $idRaw === '' ? 0 : filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    set_flash('error', 'The member ID is invalid.');
    redirect('/members/index.php');
}
$id = (int) $id;
$values = [
    'member_id' => $id > 0 ? $id : 0,
    'member_code' => post_string('member_code'),
    'full_name' => post_string('full_name'),
    'course' => post_string('course'),
    'section_group' => post_string('section_group'),
    'semester' => post_string('semester'),
    'phone' => post_string('phone'),
    'email' => post_string('email'),
];
$errors = [];
if ($id < 0) $errors['member_id'] = 'The member ID is invalid.';
if ($values['member_code'] === '') $errors['member_code'] = 'Enter a member code or roll number.';
elseif (text_length($values['member_code']) > 30) $errors['member_code'] = 'The member code must be 30 characters or fewer.';
if ($values['full_name'] === '') $errors['full_name'] = 'Enter the member name.';
elseif (text_length($values['full_name']) > 150) $errors['full_name'] = 'The name must be 150 characters or fewer.';
if (!valid_course($values['course'])) $errors['course'] = 'Choose a program from the college course list.';
if (text_length($values['section_group']) > 80) $errors['section_group'] = 'The section or group must be 80 characters or fewer.';
if ($values['semester'] === '') $semester = null;
else {
    $semester = filter_var($values['semester'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
    if ($semester === false) $errors['semester'] = 'Choose a semester from 1 to 8.';
    elseif (valid_course($values['course']) && $semester > course_semester_limit($values['course'])) $errors['semester'] = 'This program has a maximum of ' . course_semester_limit($values['course']) . ' semesters.';
}
if (text_length($values['phone']) > 20 || ($values['phone'] !== '' && !preg_match('/^[0-9+().\s-]{6,20}$/', $values['phone']))) $errors['phone'] = 'Enter a phone number using digits and common separators.';
if (text_length($values['email']) > 254 || ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL))) $errors['email'] = 'Enter a valid email address.';
$formUrl = '/members/form.php' . ($id > 0 ? '?id=' . $id : '');
if ($errors) {
    save_form_state('members', $values, $errors);
    redirect($formUrl);
}
$course = $values['course'] === '' ? null : $values['course'];
$sectionGroup = $values['section_group'] === '' ? null : $values['section_group'];
$phone = $values['phone'] === '' ? null : $values['phone'];
$email = $values['email'] === '' ? null : $values['email'];

try {
    if ($id > 0) {
        $exists = $conn->prepare('SELECT member_id FROM members WHERE member_id = ?');
        $exists->bind_param('i', $id);
        $exists->execute();
        if (!$exists->get_result()->fetch_assoc()) {
            set_flash('error', 'That member record no longer exists.');
            redirect('/members/index.php');
        }
    }
    $duplicateSql = 'SELECT member_id FROM members WHERE member_code = ?' . ($id > 0 ? ' AND member_id <> ?' : '') . ' LIMIT 1';
    $duplicate = $conn->prepare($duplicateSql);
    if ($id > 0) $duplicate->bind_param('si', $values['member_code'], $id);
    else $duplicate->bind_param('s', $values['member_code']);
    $duplicate->execute();
    if ($duplicate->get_result()->fetch_assoc()) {
        save_form_state('members', $values, ['member_code' => 'That member code is already in use.']);
        redirect($formUrl);
    }
    if ($id > 0) {
        $statement = $conn->prepare('UPDATE members SET member_code = ?, full_name = ?, course = ?, section_group = ?, semester = ?, phone = ?, email = ? WHERE member_id = ?');
        $statement->bind_param('ssssissi', $values['member_code'], $values['full_name'], $course, $sectionGroup, $semester, $phone, $email, $id);
        $statement->execute();
        set_flash('success', 'Member changes saved.');
    } else {
        $statement = $conn->prepare('INSERT INTO members (member_code, full_name, course, section_group, semester, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bind_param('ssssiss', $values['member_code'], $values['full_name'], $course, $sectionGroup, $semester, $phone, $email);
        $statement->execute();
        set_flash('success', 'Member added to the register.');
    }
    redirect('/members/index.php');
} catch (mysqli_sql_exception $exception) {
    save_form_state('members', $values, []);
    set_flash('error', 'The member could not be saved. Check that the member code is unique and try again.');
    redirect($formUrl);
}
