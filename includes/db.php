<?php
declare(strict_types=1);

function db(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $configPath = dirname(__DIR__) . '/config/local.php';
    $config = is_file($configPath) ? require $configPath : [];
    $environment = strtolower((string) (getenv('APP_ENV') ?: 'local'));
    $host = (string) ($config['host'] ?? getenv('LIBRARYFLOW_DB_HOST') ?: '127.0.0.1');
    $port = (int) ($config['port'] ?? getenv('LIBRARYFLOW_DB_PORT') ?: 3306);
    $database = (string) ($config['database'] ?? getenv('LIBRARYFLOW_DB_NAME') ?: 'libraryflow');
    $username = (string) ($config['username'] ?? getenv('LIBRARYFLOW_DB_USER') ?: ($environment === 'production' ? '' : 'root'));
    $password = (string) ($config['password'] ?? getenv('LIBRARYFLOW_DB_PASSWORD') ?: '');

    if ($environment === 'test' && !str_starts_with($database, 'libraryflow_test_')) {
        http_response_code(503);
        exit('Tests must use a database whose name starts with libraryflow_test_.');
    }

    if ($environment === 'production') {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        if ($username === '' || $username === 'root' || $password === '') {
            http_response_code(503);
            exit('The database service is not configured. Contact the site administrator.');
        }
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $connection = new mysqli($host, $username, $password, $database, $port);
        $connection->set_charset('utf8mb4');
        return $connection;
    } catch (mysqli_sql_exception $exception) {
        error_log('LibraryFlow database connection failed (' . $exception->getCode() . ').');
        http_response_code(503);
        $styleUrl = htmlspecialchars(url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Database setup required | LibraryFlow</title><link rel="stylesheet" href="' . $styleUrl . '"></head>';
        echo '<body class="setup-page"><main class="setup-card"><div class="eyebrow">LibraryFlow ERP</div><h1>Database connection unavailable</h1>';
        echo '<p>Start MySQL in XAMPP and import <code>database/libraryflow.sql</code>. If your local credentials differ, copy <code>config/local.php.example</code> to <code>config/local.php</code> and update it.</p>';
        echo '<p class="muted">The connection details are hidden. See the setup steps in README.md.</p></main></body></html>';
        exit;
    }
}
