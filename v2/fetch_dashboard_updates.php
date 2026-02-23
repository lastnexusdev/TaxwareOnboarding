<?php
include 'db.php';

// Fetch New Software Release setting
$software_release_sql = "SELECT Setting_Value FROM admin_settings WHERE Setting_Name = 'NewSoftwareRelease'";
$software_release_result = $conn->query($software_release_sql);
$new_software_release = $software_release_result->fetch_assoc()['Setting_Value'] ?? 0;

// Fetch clients data
$clients_sql = "SELECT ClientID, Progress, Completed, CompletedUntilNewVersion, Cancelled, Stalled, RowColor FROM Onboarding";
$clients_result = $conn->query($clients_sql);

$clients = [];
if ($clients_result->num_rows > 0) {
    while($client = $clients_result->fetch_assoc()) {
        $client['NewSoftwareRelease'] = $new_software_release;
        $clients[] = $client;
    }
}

echo json_encode(['success' => true, 'data' => $clients]);
$conn->close();
?>
