<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$servername = "localhost";
$username = "admin";
$password = "XTelvista368!1985X";
$dbname = "onboarding";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error, 3, "/path/to/your/log/file.log");
    die("Connection failed: " . $conn->connect_error);
}

// Function to log SQL errors
function log_sql_error($stmt) {
    $error = $stmt->error;
    $sqlstate = $stmt->sqlstate;
    error_log("SQL error: $error, SQLSTATE: $sqlstate\n", 3, "/path/to/your/log/file.log");
}

?>
