<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Optional role restriction (set $requireRoles before include)
if (isset($requireRoles) && is_array($requireRoles)) {
    if (!in_array($_SESSION['role'], $requireRoles)) {
        echo "<h2>Access denied: insufficient permissions.</h2>";
        exit;
    }
}
?>
