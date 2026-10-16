<?php
declare(strict_types=1);

function url(string $path = ''): string
{
    $base = APP_BASE_URL;
    if ($path === '') {
        return ($base === '' ? '' : $base) . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('Request could not be verified. Return to the previous page and try again.');
    }
}

function save_form_state(string $key, array $values, array $errors): void
{
    $_SESSION['form_state'][$key] = ['values' => $values, 'errors' => $errors];
}

function consume_form_state(string $key): array
{
    $state = $_SESSION['form_state'][$key] ?? ['values' => [], 'errors' => []];
    unset($_SESSION['form_state'][$key]);
    return $state;
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function query_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function normalize_isbn(string $value): string
{
    return strtoupper((string) preg_replace('/[\s-]+/', '', $value));
}

function valid_isbn(string $value): bool
{
    $isbn = normalize_isbn($value);
    return (bool) preg_match('/^(?:[0-9]{9}[0-9X]|[0-9]{13})$/', $isbn);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function password_requirements(string $password): array
{
    return [
        'length' => text_length($password) >= 8 && text_length($password) <= 200,
        'lowercase' => (bool) preg_match('/[a-z]/', $password),
        'uppercase' => (bool) preg_match('/[A-Z]/', $password),
        'special' => (bool) preg_match('/[^A-Za-z0-9\s]/', $password),
    ];
}

function valid_password(string $password): bool
{
    return !in_array(false, password_requirements($password), true);
}

function limit_text(string $value, int $maximum): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maximum, 'UTF-8');
    }
    if (preg_match('/\A.{0,' . $maximum . '}/us', $value, $match)) {
        return $match[0];
    }
    return substr($value, 0, $maximum);
}

function valid_iso_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function display_date(?string $value): string
{
    if (!$value) {
        return 'Not recorded';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('d M Y') : e($value);
}

function status_badge(string $label, string $tone = 'neutral'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($label) . '</span>';
}

function field_error(array $errors, string $field): string
{
    if (empty($errors[$field])) {
        return '';
    }
    return '<span class="field-error" id="' . e($field) . '-error">' . e($errors[$field]) . '</span>';
}

function bind_values(mysqli_stmt $statement, string $types, array $values): void
{
    if ($types === '') {
        return;
    }
    $references = [];
    foreach ($values as $index => &$value) {
        $references[$index] = &$value;
    }
    unset($value);
    array_unshift($references, $types);
    call_user_func_array([$statement, 'bind_param'], $references);
}

function render_header(string $title, string $active = ''): void
{
    $pageTitle = $title;
    $activePage = $active;
    $currentUser = current_user();
    require dirname(__DIR__) . '/includes/header.php';
}

function render_footer(): void
{
    require dirname(__DIR__) . '/includes/footer.php';
}
