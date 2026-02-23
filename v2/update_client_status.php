<?php
include 'db.php';

// Get POST data
$client_id = $_POST['client_id'] ?? null;
$status_field = $_POST['status_field'] ?? null;
$status_value = $_POST['status_value'] ?? null;

if ($client_id && $status_field && isset($status_value)) {
    $update_sql = "UPDATE Onboarding SET $status_field = ? WHERE ClientID = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param('ii', $status_value, $client_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false]);
}

$conn->close();
?>
