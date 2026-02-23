<?php
session_start(); // Start session if not already started
include 'db.php';

// Set up error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/onboarding_error.log'); // Ensure the 'logs' directory exists and is writable

header('Content-Type: application/json');

// Check if the user is logged in
if (!isset($_SESSION['userid'])) {
    error_log('Access denied: User not logged in'); // Log error
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// Capture client_id, item, and status from POST request
$client_id = isset($_POST['client_id']) ? $_POST['client_id'] : null; // Keep as string to allow hyphens
$item = isset($_POST['item']) ? $_POST['item'] : null;
$status = isset($_POST['status']) ? intval($_POST['status']) : null;

if ($client_id === null || $item === null || $status === null) {
    error_log("Invalid request: Missing client_id, item, or status. client_id={$client_id}, item={$item}, status={$status}");
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

// Define checklist items
$checklist_items = [
    'Pre-Installation Preparation' => [
        'ConfirmContactInfo', 'ReviewRequirements', 'ScheduleAppointment'
    ],
    'Download' => [
        'DownloadSoftware', 'InformClient', 'StartInstallation'
    ],
    'Setup' => [
        'EnterUserID', 'ConfigureSettings', 'ManageUserAccounts'
    ],
    'Testing' => [
        'RunSoftware', 'ProvideWalkthrough', 'DemonstrateTasks'
    ],
    'Client Data Conversion' => [
        'VerifyPlanData', 'ExecuteConversion', 'VerifyIntegrity', 'TransferSetupData'
    ],
    'Final Steps' => [
        'ContactSupport', 'OfferResources', 'ProvideTrainingInfo', 'ScheduleFollowUp'
    ]
];

// Additional items based on conditions
$software_release_sql = "SELECT Setting_Value FROM admin_settings WHERE Setting_Name = 'NewSoftwareRelease'";
$software_release_result = $conn->query($software_release_sql);
$new_software_release = $software_release_result->fetch_assoc()['Setting_Value'] ?? 0;

if ($new_software_release == 1) {
    $checklist_items['Download'][] = 'InstalledNewVersion';
}

// Fetch client details
$client_sql = "SELECT * FROM Onboarding WHERE ClientID = ?";
$stmt = $conn->prepare($client_sql);
if (!$stmt) {
    error_log("Failed to prepare client query: " . $conn->error); // Log database preparation error
    echo json_encode(['success' => false, 'error' => 'Database query preparation failed.']);
    exit;
}
$stmt->bind_param('s', $client_id); // Bind as string
$stmt->execute();
$client_result = $stmt->get_result();
$client = $client_result->fetch_assoc();
$stmt->close();

if ($client['ConvertionNeeded'] === 'Yes') {
    $checklist_items['Client Data Conversion'] = array_merge($checklist_items['Client Data Conversion'], ['VerifyPlanData', 'ExecuteConversion', 'VerifyIntegrity', 'TransferSetupData']);
}

if ($client['BankEnrollment'] === 'Yes') {
    $checklist_items['Setup'][] = 'CompleteBankEnrollment';
}

// Build allowed items list
$allowed_items = [];
foreach ($checklist_items as $section_items) {
    foreach ($section_items as $allowed_item) {
        $allowed_items[] = $allowed_item;
    }
}

// Validate $item
if (!in_array($item, $allowed_items)) {
    error_log("Invalid checklist item: {$item}");
    echo json_encode(['success' => false, 'error' => 'Invalid checklist item.']);
    exit;
}

// Update checklist item status
$update_sql = "UPDATE Onboarding SET $item = ? WHERE ClientID = ?";
$stmt = $conn->prepare($update_sql);
if ($stmt === false) {
    error_log("Failed to prepare statement for updating checklist item: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Failed to prepare statement.']);
    exit;
}
$stmt->bind_param('is', $status, $client_id); // Bind client_id as a string

if ($stmt->execute() === false) {
    error_log("Failed to execute update_progress_sql: " . $stmt->error . " with Progress Percentage: $progress_percentage and Client ID: $client_id");
    echo json_encode(['success' => false, 'error' => 'Failed to execute statement.']);
    exit;
}
$stmt->close();

// Re-fetch updated client details to recalculate progress
$stmt = $conn->prepare($client_sql);
$stmt->bind_param('s', $client_id); // Bind as string
$stmt->execute();
$client_result = $stmt->get_result();
$client = $client_result->fetch_assoc();
$stmt->close();

// Calculate total progress, excluding "Client Data Conversion" if conversion is not needed
$total_items = 0;
$completed_items = 0;

foreach ($checklist_items as $section => $section_items) {
    // Skip "Client Data Conversion" section if conversion is not needed
    if ($section === 'Client Data Conversion' && $client['ConvertionNeeded'] === 'No') {
        continue;
    }
    
    $total_items += count($section_items);
    foreach ($section_items as $item_key) {
        if ($client[$item_key]) {
            $completed_items++;
        }
    }
}

$progress_percentage = $total_items > 0 ? round(($completed_items / $total_items) * 100, 2) : 0.00;
error_log("Calculated progress_percentage: $progress_percentage for client_id: $client_id"); // Log the progress value


$progress_percentage = $total_items > 0 ? round(($completed_items / $total_items) * 100, 2) : 0.00;
error_log("Calculated progress_percentage: $progress_percentage for client_id: $client_id"); // Log the progress value

// Update progress in the database
$update_progress_sql = "UPDATE Onboarding SET Progress = ? WHERE ClientID = ?";
$stmt = $conn->prepare($update_progress_sql);
if (!$stmt) {
    error_log("Failed to prepare update_progress_sql: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Failed to prepare statement.']);
    exit;
}

$stmt->bind_param('ds', $progress_percentage, $client_id); // Bind client_id as a string

if (!$stmt->execute()) {
    error_log("Failed to execute update_progress_sql: " . $stmt->error . " with Progress Percentage: $progress_percentage and Client ID: $client_id");
    echo json_encode(['success' => false, 'error' => 'Failed to update progress.']);
    exit;
}

$stmt->close();

// Set CompletedUntilNewVersion or Completed based on progress and software release status
if ($progress_percentage == 100) {
    if ($new_software_release == 1 && !$client['InstalledNewVersion']) {
        $update_status_sql = "UPDATE Onboarding SET CompletedUntilNewVersion = 1 WHERE ClientID = ?";
    } else {
        $update_status_sql = "UPDATE Onboarding SET Completed = 1 WHERE ClientID = ?";
    }
    $stmt = $conn->prepare($update_status_sql);
    $stmt->bind_param('s', $client_id); // Bind as string
    if ($stmt->execute() === false) {
        error_log("Failed to update completion status: " . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
        exit;
    }
    $stmt->close();
} else {
    $update_status_sql = "UPDATE Onboarding SET CompletedUntilNewVersion = 0, Completed = 0 WHERE ClientID = ?";
    $stmt = $conn->prepare($update_status_sql);
    $stmt->bind_param('s', $client_id); // Bind as string
    if ($stmt->execute() === false) {
        error_log("Failed to reset completion status: " . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Failed to reset status.']);
        exit;
    }
    $stmt->close();
}

// Send response
echo json_encode(['success' => true, 'progress' => $progress_percentage, 'error' => '']);
?>
