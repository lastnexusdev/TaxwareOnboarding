<?php
require_once "auth_check.php"; // forces login check
include 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['userid'])) {
    echo "Access denied.";
    exit;
}

$client_id = $_GET['client_id'] ?? null;
if ($client_id === null) {
    echo "Client ID not provided.";
    exit;
}

// --- Fetch client details safely ---
$stmt = $conn->prepare("SELECT * FROM Onboarding WHERE ClientID = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$client_result = $stmt->get_result();
$client = $client_result->fetch_assoc();
$stmt->close();

if (!$client) {
    echo "Client not found.";
    exit;
}

// --- Assigned tech check ---
$is_assigned_tech = $client['AssignedTech'] == $_SESSION['userid'];

// --- Handle First Callout submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_first_callout']) && $is_assigned_tech) {
    $first_callout = $_POST['FirstCallout'] ?? date('Y-m-d');
    
    // Check if details record exists
    $check_sql = "SELECT DetailID FROM OnboardingDetails WHERE ClientID = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $client_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $details_exists = $check_result->num_rows > 0;
    $check_stmt->close();
    
    if ($details_exists) {
        // Update existing record
        $update_sql = "UPDATE OnboardingDetails SET FirstCallout = ? WHERE ClientID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ss", $first_callout, $client_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO OnboardingDetails (ClientID, FirstCallout) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ss", $client_id, $first_callout);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Log to history
    $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails, EditedBy) VALUES (?, 'First Callout', ?, ?)";
    $history_stmt = $conn->prepare($history_sql);
    $action_details = "First callout completed on " . $first_callout;
    $history_stmt->bind_param("ssi", $client_id, $action_details, $_SESSION['userid']);
    $history_stmt->execute();
    $history_stmt->close();
    
    // Refresh the page to show updated data
    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=first_callout");
    exit;
}

// --- Handle Follow Up Calls submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_follow_up_calls']) && $is_assigned_tech) {
    $follow_up_calls = trim($_POST['FollowUpCalls'] ?? '');
    
    // Check if details record exists
    $check_sql = "SELECT DetailID FROM OnboardingDetails WHERE ClientID = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $client_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $details_exists = $check_result->num_rows > 0;
    $check_stmt->close();
    
    if ($details_exists) {
        // Update existing record
        $update_sql = "UPDATE OnboardingDetails SET FollowUpCalls = ? WHERE ClientID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ss", $follow_up_calls, $client_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO OnboardingDetails (ClientID, FollowUpCalls) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ss", $client_id, $follow_up_calls);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Log to history
    $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails, EditedBy) VALUES (?, 'Follow Up Calls', ?, ?)";
    $history_stmt = $conn->prepare($history_sql);
    $action_details = "Follow up calls updated: " . substr($follow_up_calls, 0, 100) . (strlen($follow_up_calls) > 100 ? '...' : '');
    $history_stmt->bind_param("ssi", $client_id, $action_details, $_SESSION['userid']);
    $history_stmt->execute();
    $history_stmt->close();
    
    // Refresh the page to show updated data
    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=follow_up");
    exit;
}

// --- Handle Notes submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_notes']) && $is_assigned_tech) {
    $notes = trim($_POST['Notes'] ?? '');
    
    // Check if details record exists
    $check_sql = "SELECT DetailID FROM OnboardingDetails WHERE ClientID = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $client_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $details_exists = $check_result->num_rows > 0;
    $check_stmt->close();
    
    if ($details_exists) {
        // Update existing record
        $update_sql = "UPDATE OnboardingDetails SET Notes = ? WHERE ClientID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ss", $notes, $client_id);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_sql = "INSERT INTO OnboardingDetails (ClientID, Notes) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ss", $client_id, $notes);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Log to history
    $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails, EditedBy) VALUES (?, 'Notes Updated', ?, ?)";
    $history_stmt = $conn->prepare($history_sql);
    $action_details = "Notes updated: " . substr($notes, 0, 100) . (strlen($notes) > 100 ? '...' : '');
    $history_stmt->bind_param("ssi", $client_id, $action_details, $_SESSION['userid']);
    $history_stmt->execute();
    $history_stmt->close();
    
    // Refresh the page to show updated data
    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=notes");
    exit;
}

// --- Handle edit history submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_history']) && $is_assigned_tech) {
    $history_id = intval($_POST['history_id']);
    $new_details = trim($_POST['new_details']);
    
    $edit_sql = "UPDATE OnboardingHistory SET ActionDetails = ?, EditedBy = ? WHERE HistoryID = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param("sii", $new_details, $_SESSION['userid'], $history_id);
    $edit_stmt->execute();
    $edit_stmt->close();
    
    // Refresh the page
    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=history");
    exit;
}

// --- Handle folder creation for conversion files ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_folder']) && $is_assigned_tech) {
    $upload_token = bin2hex(random_bytes(16));
    
    // Update the upload token in database
    $update_token_sql = "UPDATE Onboarding SET UploadToken = ? WHERE ClientID = ?";
    $token_stmt = $conn->prepare($update_token_sql);
    $token_stmt->bind_param("ss", $upload_token, $client_id);
    $token_stmt->execute();
    $token_stmt->close();
    
    // Create the folder
    $upload_folder = __DIR__ . '/data/' . $upload_token;
    if (!file_exists($upload_folder)) {
        mkdir($upload_folder, 0777, true);
    }
    
    // Refresh to show the upload link
    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=folder");
    exit;
}

// Check for success messages
$folder_message = '';
if (isset($_GET['success'])) {
    switch($_GET['success']) {
        case 'first_callout':
            $folder_message = "First Callout date saved successfully!";
            break;
        case 'follow_up':
            $folder_message = "Follow Up Calls saved successfully!";
            break;
        case 'notes':
            $folder_message = "Notes saved successfully!";
            break;
        case 'history':
            $folder_message = "History updated successfully!";
            break;
        case 'folder':
            $folder_message = "Upload folder created successfully!";
            break;
    }
}

// Define checklist items
$checklist_items = [
    'Pre-Installation Preparation' => [
        'ConfirmContactInfo' => 'Confirm client\'s name and contact information',
        'ReviewRequirements' => 'Specific requirements and configuration',
        'ScheduleAppointment' => 'Schedule Appointment if they don\'t have time for install at this moment'
    ],
    'Download' => [
        'DownloadSoftware' => 'Download the latest version of Taxware software',
        'InformClient' => 'Inform Client Of New Version And Possible Time Frames',
        'StartInstallation' => 'Complete Installation'
    ],
    'Setup' => [
        'EnterUserID' => 'Enter User-ID into all Software Installed',
        'ConfigureSettings' => 'Configure the basic settings as per the client\'s requirements (SETUP ERO AND PREPARER INFO)',
        'ManageUserAccounts' => 'Provide instructions on how to manage user accounts (Winuser, Passwords)'
    ],
    'Testing' => [
        'RunSoftware' => 'Run the software to ensure it starts correctly',
        'ProvideWalkthrough' => 'Provide a brief walkthrough of the software features',
        'DemonstrateTasks' => 'Demonstrate how to perform basic tasks and use essential functions (Taxware Connect, Videos, Website, Updates)'
    ],
    'Client Data Conversion' => [
        'VerifyPlanData' => 'Verify and plan data for conversion',
        'ExecuteConversion' => 'Execute conversion of client data',
        'VerifyIntegrity' => 'Verify the integrity of converted data',
        'TransferSetupData' => 'Transfer and setup data on Client side'
    ],
    'Final Steps' => [
        'ContactSupport' => 'Ensure the client knows how to contact support for further assistance',
        'OfferResources' => 'Offer links to online resources and tutorials',
        'ProvideTrainingInfo' => 'Provide information on upcoming training sessions or webinars',
        'ScheduleFollowUp' => 'Schedule a follow-up call to address any new questions they might have'
    ]
];

// --- Software release flag ---
$software_release_result = $conn->query("SELECT Setting_Value FROM admin_settings WHERE Setting_Name = 'NewSoftwareRelease'");
$software_release_row = $software_release_result ? $software_release_result->fetch_assoc() : null;
$new_software_release = $software_release_row['Setting_Value'] ?? 0;

if ($new_software_release == 1) {
    $checklist_items['Download']['InstalledNewVersion'] = 'Installed New Version';
}

// --- Adjust checklist for client-specific flags ---
if ($client['ConvertionNeeded'] === 'Yes') {
    $checklist_items['Client Data Conversion'] = array_merge($checklist_items['Client Data Conversion'], [
        'VerifyPlanData' => 'Verify and plan data for conversion',
        'ExecuteConversion' => 'Execute conversion of client data',
        'VerifyIntegrity' => 'Verify the integrity of converted data',
        'TransferSetupData' => 'Transfer and setup data on Client side'
    ]);
}

if ($client['BankEnrollment'] === 'Yes') {
    $checklist_items['Setup']['CompleteBankEnrollment'] = 'Complete Bank Enrollment';
}

// --- Additional details ---
$stmt = $conn->prepare("SELECT * FROM OnboardingDetails WHERE ClientID = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$details_result = $stmt->get_result();
$details = $details_result->fetch_assoc();
$stmt->close();

// --- History ---
$history_sql = "SELECT h.*, u.FirstName, u.LastName 
                FROM OnboardingHistory h 
                LEFT JOIN Users u ON h.EditedBy = u.UserID 
                WHERE h.ClientID = ? ORDER BY h.ActionTimestamp DESC";
$stmt = $conn->prepare($history_sql);
$stmt->bind_param("s", $client_id);
$stmt->execute();
$history_result = $stmt->get_result();
$stmt->close();

// --- Assigned tech ---
$tech_first_name = '';
if (!empty($client['AssignedTech'])) {
    $stmt = $conn->prepare("SELECT FirstName FROM Users WHERE UserID = ?");
    $stmt->bind_param("i", $client['AssignedTech']);
    $stmt->execute();
    $tech_result = $stmt->get_result();
    $tech = $tech_result->fetch_assoc();
    $tech_first_name = $tech['FirstName'] ?? '';
    $stmt->close();
}

// --- Entitled Programs ---
$stmt = $conn->prepare("SELECT * FROM EntitledPrograms WHERE ClientID = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$entitled_result = $stmt->get_result();
$entitled_programs = $entitled_result->fetch_assoc();
$stmt->close();

$program_names = [
    "prog_1040" => "1040", "prog_1120" => "1120", "prog_1120S" => "1120S", "prog_1065" => "1065",
    "prog_1041" => "1041", "prog_706Estate" => "706Estate", "prog_709Gift" => "709Gift",
    "prog_990Exempt" => "990Exempt", "prog_DocArk" => "DocArk", "prog_1099Acc" => "1099Acc",
    "prog_Depreciation" => "Depreciation", "prog_Proforma" => "Proforma", "prog_Winpay" => "Winpay",
    "prog_GeneralLedger" => "GeneralLedger", "prog_MoneyManager" => "MoneyManager"
];
$entitled_program_list = [];
if ($entitled_programs) {
    foreach ($entitled_programs as $prog_key => $prog_value) {
        if ($prog_value == 1 && isset($program_names[$prog_key])) {
            $entitled_program_list[] = $program_names[$prog_key];
        }
    }
}

// --- Upload token ---
$stmt = $conn->prepare("SELECT UploadToken FROM Onboarding WHERE ClientID = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$upload_token_result = $stmt->get_result();
$upload_token_row = $upload_token_result->fetch_assoc();
$stmt->close();
$upload_token = $upload_token_row['UploadToken'] ?? null;

// --- File listing ---
$uploaded_files = [];
if ($upload_token) {
    $upload_folder = __DIR__ . '/data/' . $upload_token;
    if (file_exists($upload_folder)) {
        $uploaded_files = array_diff(scandir($upload_folder), ['.', '..']);
    }
}

// --- Progress calculation ---
$total_items = 0;
$completed_items = 0;
foreach ($checklist_items as $section_items) {
    $total_items += count($section_items);
    foreach ($section_items as $item => $description) {
        if (!empty($client[$item])) {
            $completed_items++;
        }
    }
}
$progress_percentage = $total_items > 0 ? ($completed_items / $total_items) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Onboarding Details - <?php echo htmlspecialchars($client['ClientName']); ?></title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            background-color: #f4f4f4;
            background-image: url('https://kb.taxwaresystems.com/web_texture_mirrored.svg');
            background-repeat: repeat;
            background-attachment: fixed;
        }

        .top-menu {
            margin: 0;
            background-color: #8B4513;
            padding: 10px 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            position: relative;
        }
        .top-menu-brand {
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
        }

        .top-menu-brand img {
            height: 42px;
            width: auto;
            display: block;
        }

        .top-menu ul {
            list-style-type: none;
            padding: 0;
            display: flex;
            gap: 10px;
            margin: 0;
        }

        .top-menu ul li {
            margin: 0;
        }

        .top-menu ul li a {
            display: block;
            padding: 10px 15px;
            background-color: #6d4c41;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .top-menu ul li a:hover {
            background-color: #5d4037;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .page-header h2 {
            margin: 0 0 10px 0;
            color: #8B4513;
            border-bottom: 2px solid #8B4513;
            padding-bottom: 10px;
        }

        .progress-section {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .progress-bar {
            width: 100%;
            background-color: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            height: 30px;
            position: relative;
        }

        .progress-bar-inner {
            height: 100%;
            background: linear-gradient(90deg, #8B4513 0%, #6d4c41 100%);
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }

        .progress-percentage {
            font-size: 18px;
            font-weight: bold;
            color: #8B4513;
            margin-top: 10px;
        }

        .dual-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 968px) {
            .dual-column {
                grid-template-columns: 1fr;
            }
        }

        .client-info, .checklist {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .client-info h3, .checklist h3 {
            margin-top: 0;
            color: #8B4513;
            border-bottom: 2px solid #8B4513;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }

        .info-item strong {
            display: block;
            color: #495057;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .info-item .value {
            color: #8B4513;
            font-weight: bold;
            font-size: 16px;
        }

        .entitled-programs {
            margin-top: 20px;
        }

        .entitled-programs ul {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }

        .entitled-programs ul li {
            background-color: #f5e6d3;
            padding: 8px 12px;
            border-radius: 4px;
            text-align: center;
            color: #8B4513;
            font-weight: 500;
            border: 1px solid #d7b899;
        }

        .checklist ul {
            list-style: none;
            padding-left: 0;
        }

        .checklist ul li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }

        .checklist ul li:last-child {
            border-bottom: none;
        }

        .checklist input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checklist input[type="checkbox"]:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        .additional-info {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }

        .additional-info h3 {
            margin-top: 0;
            color: #8B4513;
            border-bottom: 2px solid #8B4513;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #495057;
        }

        .form-group input[type="date"],
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input[type="submit"],
        .btn {
            background-color: #8B4513;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .form-group input[type="submit"]:hover,
        .btn:hover {
            background-color: #6d4c41;
        }

        .form-group input[type="submit"]:disabled,
        .btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .upload-link-container {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 20px;
        }

        .upload-link {
            display: inline-block;
            padding: 10px 20px;
            background-color: #8B4513;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
            font-size: 14px;
            line-height: 1.5;
            border: none;
        }

        .upload-link:hover {
            background-color: #6d4c41;
        }

        .copy-upload-link-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1.5;
            transition: background-color 0.3s;
        }

        .copy-upload-link-btn:hover {
            background-color: #5a6268;
        }

        .copy-upload-link-btn.copied {
            background-color: #28a745;
        }

        .file-list {
            margin-top: 15px;
        }

        .file-list h4 {
            color: #495057;
            margin-bottom: 10px;
        }

        .file-list ul {
            list-style: none;
            padding: 0;
        }

        .file-list ul li {
            padding: 8px 12px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 5px;
        }

        .file-list ul li a {
            color: #8B4513;
            text-decoration: none;
        }

        .file-list ul li a:hover {
            text-decoration: underline;
        }
.history {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }

        .history h3 {
            margin-top: 0;
            color: #8B4513;
            border-bottom: 2px solid #8B4513;
            padding-bottom: 10px;
        }

        .history table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .history th, .history td {
            border: 1px solid #ddd;
            padding: 8px;
            word-wrap: break-word;
            white-space: normal;
            overflow-wrap: break-word;
            vertical-align: top;
        }

        .history th {
            background-color: #8B4513;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 14px;
        }

        .history tr:hover {
            background-color: #f8f9fa;
        }

        .history .first-callout {
            background-color: #d4edda;
        }

        .history button {
            background-color: #ffc107;
            color: #000;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }

        .history button:hover {
            background-color: #e0a800;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 20px;
        }

        .close:hover,
        .close:focus {
            color: #000;
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-spanish {
            background-color: #28a745;
            color: white;
        }

        .badge-conversion {
            background-color: #ffc107;
            color: #000;
        }

        .badge-bank {
            background-color: #17a2b8;
            color: white;
        }
    </style>
    <script>
        function openModal(historyId, actionDetails) {
            document.getElementById("history_id").value = historyId;
            document.getElementById("new_details").value = actionDetails;
            document.getElementById("editModal").style.display = "block";
        }

        function closeModal() {
            document.getElementById("editModal").style.display = "none";
        }

        function updateChecklist(item) {
            $.ajax({
                url: 'update_checklist.php',
                type: 'POST',
                data: {
                    client_id: <?php echo json_encode($client_id); ?>,
                    item: item.name,
                    status: item.checked ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        $("#progress-bar-inner").css("width", response.progress + "%");
                        $("#progress-bar-inner").text(response.progress.toFixed(2) + "%");
                        $(".progress-percentage").text("Progress: " + response.progress.toFixed(2) + "%");
                    } else {
                        alert("Failed to update checklist. Please try again.");
                    }
                },
                error: function() {
                    alert("An error occurred. Please try again.");
                }
            });
        }

        function copyUploadLink(url, button) {
            // Create a temporary input element
            const tempInput = document.createElement('input');
            tempInput.value = url;
            document.body.appendChild(tempInput);
            tempInput.select();
            tempInput.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                // Copy to clipboard
                document.execCommand('copy');
                
                // Visual feedback
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                button.classList.add('copied');
                
                // Reset after 2 seconds
                setTimeout(function() {
                    button.textContent = originalText;
                    button.classList.remove('copied');
                }, 2000);
            } catch (err) {
                alert('Failed to copy link. Please try again.');
            }
            
            // Remove temporary input
            document.body.removeChild(tempInput);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</head>
<body>
    <div class="top-menu">
        <a href="dashboard.php" class="top-menu-brand" aria-label="Taxware Systems Home">
            <img src="https://kb.taxwaresystems.com/logo.png" alt="Taxware Systems">
        </a>
        <ul>
            <li><a href="dashboard.php">? Back to Dashboard</a></li>
        </ul>
        <div>
            <?php if ($client['Spanish'] == 'Yes'): ?>
                <span class="badge badge-spanish">Spanish Speaker</span>
            <?php endif; ?>
            <?php if ($client['ConvertionNeeded'] == 'Yes'): ?>
                <span class="badge badge-conversion">Conversion Needed</span>
            <?php endif; ?>
            <?php if ($client['BankEnrollment'] == 'Yes'): ?>
                <span class="badge badge-bank">Bank Enrollment</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h2>Onboarding Details for <?php echo htmlspecialchars($client['ClientName']); ?></h2>
        </div>

        <?php if (isset($folder_message) && $folder_message): ?>
            <div class="message"><?php echo htmlspecialchars($folder_message); ?></div>
        <?php endif; ?>

        <div class="progress-section">
            <h3 style="margin-top: 0; color: #8B4513;">Progress Overview</h3>
            <div class="progress-bar">
                <div id="progress-bar-inner" class="progress-bar-inner" style="width: <?php echo $progress_percentage; ?>%;">
                    <?php echo round($progress_percentage, 2); ?>%
                </div>
            </div>
            <p class="progress-percentage">Progress: <?php echo round($progress_percentage, 2); ?>%</p>
        </div>

        <div class="dual-column">
            <div class="client-info">
                <h3>Client Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Client ID</strong>
                        <span class="value"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Client Name</strong>
                        <span class="value"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Phone Number</strong>
                        <span class="value"><?php echo htmlspecialchars($client['PhoneNumber']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Email</strong>
                        <span class="value"><?php echo htmlspecialchars($client['Email']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Sales Rep</strong>
                        <span class="value"><?php echo htmlspecialchars($client['SalesRep']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Spanish Speaker</strong>
                        <span class="value"><?php echo htmlspecialchars($client['Spanish']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Previous Software</strong>
                        <span class="value"><?php echo htmlspecialchars($client['PreviousSoftware']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Conversion Needed</strong>
                        <span class="value"><?php echo htmlspecialchars($client['ConvertionNeeded']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Bank Enrollment</strong>
                        <span class="value"><?php echo htmlspecialchars($client['BankEnrollment']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Package</strong>
                        <span class="value"><?php echo htmlspecialchars($client['Package']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>Ready To Call</strong>
                        <span class="value"><?php echo $client['ReadyToCall'] ? 'Yes' : 'No'; ?></span>
                    </div>
                </div>

                <div class="entitled-programs">
                    <h3>Entitled Programs</h3>
                    <?php if (empty($entitled_program_list)): ?>
                        <p style="color: #6c757d;">No entitled programs found.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($entitled_program_list as $program): ?>
                                <li><?php echo htmlspecialchars($program); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="additional-info">
                    <h3>Additional Information</h3>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="FirstCallout">First Callout</label>
                            <input type="date" id="FirstCallout" name="FirstCallout" value="<?php echo isset($details['FirstCallout']) ? htmlspecialchars($details['FirstCallout']) : date('Y-m-d'); ?>" <?php echo isset($details['FirstCallout']) ? 'disabled' : ''; ?> <?php echo $is_assigned_tech ? '' : 'disabled'; ?>>
                            <?php if (!isset($details['FirstCallout']) && $is_assigned_tech): ?>
                                <input type="submit" name="update_first_callout" value="Submit">
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="FollowUpCalls">Follow Up Calls</label>
                            <textarea id="FollowUpCalls" name="FollowUpCalls" <?php echo $is_assigned_tech ? '' : 'disabled'; ?>><?php echo htmlspecialchars($details['FollowUpCalls'] ?? ''); ?></textarea>
                            <?php if ($is_assigned_tech): ?>
                                <input type="submit" name="update_follow_up_calls" value="Submit">
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="Notes">Notes</label>
                            <textarea id="Notes" name="Notes" <?php echo $is_assigned_tech ? '' : 'disabled'; ?>><?php echo htmlspecialchars($details['Notes'] ?? ''); ?></textarea>
                            <?php if ($is_assigned_tech): ?>
                                <input type="submit" name="update_notes" value="Save">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if ($client['ConvertionNeeded'] === 'Yes' && $is_assigned_tech): ?>
                    <?php if (empty($upload_token)): ?>
                        <div style="margin-top: 20px;">
                            <form method="POST" action="">
                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client['ClientID']); ?>">
                                <button type="submit" name="create_folder" class="btn">Create Folder for Files</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="upload-link-container">
                            <a href="upload.php?token=<?php echo htmlspecialchars($upload_token); ?>" class="upload-link">Upload Files</a>
                            <button class="copy-upload-link-btn" onclick="copyUploadLink('<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/upload.php?token=' . htmlspecialchars($upload_token); ?>', this)">Copy Link</button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($uploaded_files)): ?>
                    <div class="file-list">
                        <h4>Uploaded Files</h4>
                        <ul>
                            <?php foreach ($uploaded_files as $file): ?>
                                <li><a href="data/<?php echo htmlspecialchars($upload_token); ?>/<?php echo htmlspecialchars($file); ?>" target="_blank"><?php echo htmlspecialchars($file); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
<form method="POST" action="" class="checklist">
                <h3>Onboarding Checklist</h3>
                <?php foreach ($checklist_items as $section => $items): ?>
                    <?php if ($section != 'Client Data Conversion' || $client['ConvertionNeeded'] === 'Yes'): ?>
                        <h4 style="color: #495057; margin-top: 20px; margin-bottom: 10px; border-left: 4px solid #8B4513; padding-left: 10px;"><?php echo htmlspecialchars($section); ?></h4>
                        <ul>
                            <?php foreach ($items as $item => $description): ?>
                                <li>
                                    <input type="checkbox" 
                                           name="<?php echo htmlspecialchars($item); ?>" 
                                           <?php echo $client[$item] ? 'checked' : ''; ?> 
                                           <?php echo $is_assigned_tech ? '' : 'disabled'; ?> 
                                           onchange="updateChecklist(this)">
                                    <span><?php echo htmlspecialchars($description); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            </form>
        </div>

        <div class="history">
            <h3>History</h3>
            <table style="table-layout: auto;">
                <thead>
                    <tr>
                        <th style="width: 100px;">Actions</th>
                        <th style="width: 180px;">Date</th>
                        <th>Action Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history_result->num_rows > 0): ?>
                        <?php while ($history = $history_result->fetch_assoc()): ?>
                            <tr class="<?php echo $history['ActionType'] === 'First Callout' ? 'first-callout' : ''; ?>">
                                <td style="word-wrap: break-word; white-space: normal;">
                                    <?php if ($history['ActionType'] !== 'First Callout' && $is_assigned_tech): ?>
                                        <button type="button" onclick="openModal(<?php echo $history['HistoryID']; ?>, '<?php echo htmlspecialchars($history['ActionDetails'], ENT_QUOTES); ?>')">Edit</button>
                                    <?php endif; ?>
                                </td>
                                <td style="word-wrap: break-word; white-space: normal;"><?php echo htmlspecialchars($history['ActionTimestamp']); ?></td>
                                <td style="word-wrap: break-word; white-space: normal; max-width: none;">
                                    <?php echo htmlspecialchars($history['ActionDetails']); ?>
                                    <?php if ($history['EditedBy']): ?>
                                        <span style="color: #6c757d; font-style: italic; font-size: 12px;">
                                            (Edited by <?php echo htmlspecialchars($history['FirstName'] . ' ' . $history['LastName']); ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #6c757d;">No history records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h3 style="margin-top: 0; color: #8B4513;">Edit Action Details</h3>
                <form method="POST" action="">
                    <input type="hidden" id="history_id" name="history_id">
                    <div class="form-group">
                        <label for="new_details">Action Details:</label>
                        <textarea id="new_details" name="new_details" required></textarea>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <input type="submit" name="edit_history" value="Save Changes">
                        <button type="button" class="btn" onclick="closeModal()" style="background-color: #6c757d;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>