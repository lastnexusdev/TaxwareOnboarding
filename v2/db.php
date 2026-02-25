<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_ENV') === 'production' ? '0' : '1');

$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'admin';
$password = getenv('DB_PASSWORD') ?: 'XTelvista368!1985X';
$dbname = getenv('DB_NAME') ?: 'onboarding';
$port = (int) (getenv('DB_PORT') ?: 3306);

$conn = new mysqli($servername, $username, $password, $dbname, $port);
$conn->set_charset('utf8mb4');

/**
 * Function retained for backward compatibility with v1 code paths.
 */
function log_sql_error(mysqli_stmt $stmt): void
{
    $error = $stmt->error;
    $sqlstate = $stmt->sqlstate;
    error_log(sprintf('SQL error: %s, SQLSTATE: %s', $error, $sqlstate));
}
