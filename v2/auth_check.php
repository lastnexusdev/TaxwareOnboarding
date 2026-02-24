<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$requireRoles = $requireRoles ?? null;
if (is_array($requireRoles) && !in_array($_SESSION['role'] ?? '', $requireRoles, true)) {
    http_response_code(403);
    echo '<h2>Access denied: insufficient permissions.</h2>';
    exit;
}
