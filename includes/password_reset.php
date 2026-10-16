<?php
declare(strict_types=1);

function password_reset_settings(): array
{
    $path = dirname(__DIR__) . '/config/local.php';
    $config = is_file($path) ? require $path : [];
    $publicUrl = trim((string) ($config['public_url'] ?? getenv('LIBRARYFLOW_PUBLIC_URL') ?: ''));
    $mailFrom = trim((string) ($config['mail_from'] ?? getenv('LIBRARYFLOW_MAIL_FROM') ?: ''));
    if ($publicUrl === '' || filter_var($publicUrl, FILTER_VALIDATE_URL) === false || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
        return [];
    }

    $parts = parse_url($publicUrl);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return [];
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost)) {
        return [];
    }

    return ['public_url' => rtrim($publicUrl, '/'), 'mail_from' => $mailFrom];
}

/** Returns a raw token only in APP_ENV=test, where the test database guard is active. */
function request_password_reset(string $email): ?string
{
    $conn = db();
    $email = strtolower(trim(limit_text($email, 254)));
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $attemptKey = hash('sha256', $remoteAddress . chr(0) . $email);
    $testMode = strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'test'
        && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    $settings = $testMode ? [] : password_reset_settings();

    try {
        $conn->begin_transaction();
        $insertLimit = $conn->prepare(
            'INSERT IGNORE INTO password_reset_rate_limits (attempt_key, window_started_at, request_count)
             VALUES (?, CURRENT_TIMESTAMP, 0)'
        );
        $insertLimit->bind_param('s', $attemptKey);
        $insertLimit->execute();

        $readLimit = $conn->prepare('SELECT window_started_at, request_count FROM password_reset_rate_limits WHERE attempt_key = ? FOR UPDATE');
        $readLimit->bind_param('s', $attemptKey);
        $readLimit->execute();
        $limit = $readLimit->get_result()->fetch_assoc();
        $withinWindow = $limit && strtotime((string) $limit['window_started_at']) >= time() - 900;
        $requestCount = $withinWindow ? (int) $limit['request_count'] : 0;
        if ($requestCount >= 3) {
            $conn->commit();
            return null;
        }

        $nextCount = $requestCount + 1;
        $updateLimit = $conn->prepare(
            'UPDATE password_reset_rate_limits
             SET request_count = ?, window_started_at = IF(?,  window_started_at, CURRENT_TIMESTAMP)
             WHERE attempt_key = ?'
        );
        $resetWindow = $withinWindow ? 1 : 0;
        $updateLimit->bind_param('iis', $nextCount, $resetWindow, $attemptKey);
        $updateLimit->execute();

        $account = null;
        if (($testMode || $settings !== []) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $lookup = $conn->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
            $lookup->bind_param('s', $email);
            $lookup->execute();
            $account = $lookup->get_result()->fetch_assoc() ?: null;
        }

        if ($account === null) {
            $conn->commit();
            return null;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $userId = (int) $account['user_id'];
        $removeOld = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $removeOld->bind_param('i', $userId);
        $removeOld->execute();
        $saveToken = $conn->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, CURRENT_TIMESTAMP + INTERVAL 1 HOUR)'
        );
        $saveToken->bind_param('is', $userId, $tokenHash);
        $saveToken->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        try { $conn->rollback(); } catch (Throwable) {}
        error_log('LibraryFlow password reset request could not be processed.');
        return null;
    }

    if ($testMode) {
        return $rawToken;
    }

    $recipientQuery = $conn->prepare('SELECT email FROM users WHERE user_id = ? AND is_active = 1');
    $recipientQuery->bind_param('i', $userId);
    $recipientQuery->execute();
    $recipient = $recipientQuery->get_result()->fetch_assoc();
    $link = $settings['public_url'] . '/reset-password.php?token=' . rawurlencode($rawToken);
    $message = "A password reset was requested for your LibraryFlow account.\n\n" .
        "Use this one-time link within one hour:\n$link\n\n" .
        "If you did not request this, you can ignore this message.";
    $headers = "From: LibraryFlow <{$settings['mail_from']}>\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "X-Mailer: LibraryFlow";
    $sent = function_exists('mail') && $recipient && filter_var((string) $recipient['email'], FILTER_VALIDATE_EMAIL)
        && @mail((string) $recipient['email'], 'LibraryFlow password reset', $message, $headers);
    if (!$sent) {
        $removeFailedToken = $conn->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?');
        $removeFailedToken->bind_param('s', $tokenHash);
        $removeFailedToken->execute();
        error_log('LibraryFlow password reset email could not be delivered.');
    }
    return null;
}

function valid_password_reset_token(string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $tokenHash = hash('sha256', $token);
    $statement = db()->prepare(
        'SELECT t.user_id FROM password_reset_tokens t
         INNER JOIN users u ON u.user_id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP AND u.is_active = 1 LIMIT 1'
    );
    $statement->bind_param('s', $tokenHash);
    $statement->execute();
    $record = $statement->get_result()->fetch_assoc();
    return $record ?: null;
}

function complete_password_reset(string $token, string $password): bool
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token) || !valid_password($password)) {
        return false;
    }
    $tokenHash = hash('sha256', $token);
    $conn = db();
    $conn->begin_transaction();
    try {
        $lookup = $conn->prepare(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
        );
        $lookup->bind_param('s', $tokenHash);
        $lookup->execute();
        $tokenRecord = $lookup->get_result()->fetch_assoc();
        if (!$tokenRecord) {
            $conn->rollback();
            return false;
        }

        $userId = (int) $tokenRecord['user_id'];
        $userLookup = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? AND is_active = 1 FOR UPDATE');
        $userLookup->bind_param('i', $userId);
        $userLookup->execute();
        if (!$userLookup->get_result()->fetch_assoc()) {
            $conn->rollback();
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE user_id = ?');
        $update->bind_param('si', $passwordHash, $userId);
        $update->execute();
        $removeTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $removeTokens->bind_param('i', $userId);
        $removeTokens->execute();
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('LibraryFlow password reset could not be completed.');
        return false;
    }
}
