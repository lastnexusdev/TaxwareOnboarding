<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$client_id = $_POST['client_id'] ?? null;

if ($client_id === null) {
    echo json_encode(['success' => false, 'error' => 'Client ID not provided']);
    exit;
}

// Fetch entitled programs for this client
$stmt = $conn->prepare("SELECT * FROM EntitledPrograms WHERE ClientID = ?");
$stmt->bind_param('s', $client_id);
$stmt->execute();
$result = $stmt->get_result();
$programs = $result->fetch_assoc();
$stmt->close();

if ($programs) {
    // Remove ClientID from the array since we only want program flags
    unset($programs['ClientID']);
    echo json_encode(['success' => true, 'programs' => $programs]);
} else {
    // No entitled programs found, return empty array
    echo json_encode(['success' => true, 'programs' => []]);
}

$conn->close();
?>