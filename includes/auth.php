<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false || $userId === null) {
        return null;
    }
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
        unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_activity']);
        session_regenerate_id(true);
        return null;
    }

    $statement = db()->prepare('SELECT user_id, display_name, email, role, session_version FROM users WHERE user_id = ? AND is_active = 1');
    $statement->bind_param('i', $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;
    if ($user === null || (int) $user['session_version'] !== (int) ($_SESSION['auth_version'] ?? -1)) {
        unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_activity']);
        session_regenerate_id(true);
        $user = null;
    } else {
        $_SESSION['last_activity'] = time();
    }
    return $user;
}

function require_login(): void
{
    if (current_user() === null) {
        redirect('/login.php');
    }
}

function require_role(string $role): void
{
    $user = current_user();
    if ($user === null || !hash_equals($role, (string) ($user['role'] ?? ''))) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function login_attempt_key(string $email): string
{
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $remoteAddress . chr(0) . strtolower($email));
}

function login_is_rate_limited(string $email): bool
{
    $key = login_attempt_key($email);
    $statement = db()->prepare('SELECT locked_until FROM login_attempts WHERE attempt_key = ?');
    $statement->bind_param('s', $key);
    $statement->execute();
    $attempt = $statement->get_result()->fetch_assoc();
    return $attempt && $attempt['locked_until'] !== null && strtotime((string) $attempt['locked_until']) > time();
}

function record_login_failure(string $email): void
{
    $conn = db();
    $key = login_attempt_key($email);
    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            'INSERT IGNORE INTO login_attempts (attempt_key, failed_attempts, window_started_at)
             VALUES (?, 0, CURRENT_TIMESTAMP)'
        );
        $insert->bind_param('s', $key);
        $insert->execute();

        $select = $conn->prepare('SELECT failed_attempts, window_started_at FROM login_attempts WHERE attempt_key = ? FOR UPDATE');
        $select->bind_param('s', $key);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        $withinWindow = $row && strtotime((string) $row['window_started_at']) >= time() - 900;
        $failures = $withinWindow ? (int) $row['failed_attempts'] + 1 : 1;

        if ($failures === 1) {
            $update = $conn->prepare(
                'UPDATE login_attempts
                 SET failed_attempts = 1, window_started_at = CURRENT_TIMESTAMP, locked_until = NULL
                 WHERE attempt_key = ?'
            );
        } else {
            $update = $conn->prepare(
                'UPDATE login_attempts
                 SET failed_attempts = ?, locked_until = IF(? >= 5, CURRENT_TIMESTAMP + INTERVAL 15 MINUTE, NULL)
                 WHERE attempt_key = ?'
            );
            $update->bind_param('iis', $failures, $failures, $key);
            $update->execute();
            $conn->commit();
            return;
        }
        $update->bind_param('s', $key);
        $update->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function clear_login_failures(string $email): void
{
    $key = login_attempt_key($email);
    $statement = db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?');
    $statement->bind_param('s', $key);
    $statement->execute();
}

function attempt_login(string $email, string $password, string $requiredRole): array
{
    $email = strtolower(trim($email));
    if (login_is_rate_limited($email)) {
        return ['ok' => false, 'message' => 'Too many attempts. Wait 15 minutes, then try again.'];
    }

    $statement = db()->prepare('SELECT user_id, password_hash, role, is_active, session_version FROM users WHERE email = ? LIMIT 1');
    $statement->bind_param('s', $email);
    $statement->execute();
    $account = $statement->get_result()->fetch_assoc();
    $passwordMatches = $account && password_verify($password, (string) $account['password_hash']);
    if (!$account || !(bool) $account['is_active'] || !$passwordMatches || !hash_equals($requiredRole, (string) $account['role'])) {
        record_login_failure($email);
        return ['ok' => false, 'message' => 'Email or password is incorrect.'];
    }

    clear_login_failures($email);
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = (int) $account['user_id'];
    $_SESSION['auth_version'] = (int) $account['session_version'];
    $_SESSION['last_activity'] = time();
    $update = db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?');
    $userId = (int) $account['user_id'];
    $update->bind_param('i', $userId);
    $update->execute();
    return ['ok' => true, 'message' => ''];
}
