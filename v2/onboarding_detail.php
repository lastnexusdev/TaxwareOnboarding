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

// --- Role and edit access checks ---
$current_user_id = (int) ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
$is_assigned_tech = (string) $client['AssignedTech'] === (string) $current_user_id;
$is_sales_rep = ($_SESSION['role'] ?? '') === 'sales' || (int) ($_SESSION['department'] ?? 0) === 1;
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$is_tech_user = (int) ($_SESSION['department'] ?? 0) === 2;
$is_other_tech = $is_tech_user && !$is_assigned_tech;

if (!isset($_SESSION['temp_unlocked_clients']) || !is_array($_SESSION['temp_unlocked_clients'])) {
    $_SESSION['temp_unlocked_clients'] = [];
}

$is_temporarily_unlocked = !empty($_SESSION['temp_unlocked_clients'][$client_id]);
$can_edit_client = $is_assigned_tech || $is_sales_rep || $is_admin || $is_temporarily_unlocked;

// --- Handle temporary unlock for non-assigned techs ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_client']) && $is_other_tech) {
    $_SESSION['temp_unlocked_clients'][$client_id] = true;

    $unlock_note = sprintf('Client unlocked for temporary editing by Tech UserID %d (session only).', $current_user_id);
    $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails, EditedBy) VALUES (?, 'Client Unlocked', ?, ?)";
    $history_stmt = $conn->prepare($history_sql);
    $history_stmt->bind_param("ssi", $client_id, $unlock_note, $current_user_id);
    $history_stmt->execute();
    $history_stmt->close();

    header("Location: onboarding_detail.php?client_id=" . urlencode($client_id) . "&success=unlocked");
    exit;
}

// Refresh unlock state in case it changed above
$is_temporarily_unlocked = !empty($_SESSION['temp_unlocked_clients'][$client_id]);
$can_edit_client = $is_assigned_tech || $is_sales_rep || $is_admin || $is_temporarily_unlocked;

// --- Handle First Callout submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_first_callout']) && $can_edit_client) {
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_follow_up_calls']) && $can_edit_client) {
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_notes']) && $can_edit_client) {
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_history']) && $can_edit_client) {
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_folder']) && $can_edit_client) {
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
        case 'unlocked':
            $folder_message = "Client unlocked for editing in this session.";
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

// --- Progress value ---
// Use stored progress from DB to keep this page consistent with dashboard/list views.
$progress_percentage = isset($client['Progress']) ? (float) $client['Progress'] : 0.0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Onboarding Details - <?php echo htmlspecialchars($client['ClientName']); ?></title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function openModal(historyId, actionDetails) {
            document.getElementById("history_id").value = historyId;
            document.getElementById("new_details").value = actionDetails;
            document.getElementById("editModal").style.display = "block";
        }

        function closeModal() {
            document.getElementById("editModal").style.display = "none";
        }

        function confirmUnlock() {
            return confirm('Are you sure you want to unlock this client for editing?');
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
                        $("#progress-bar-inner").text(Math.round(response.progress) + "%");
                        $(".progress-percentage").text("Progress: " + Math.round(response.progress) + "%");
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
    <link rel="stylesheet" type="text/css" href="css/onboarding_detail.css">
</head>
<body>
    <div class="top-menu">
        <ul>
            <li><a href="dashboard.php">? Back to Dashboard</a></li>
        </ul>
    </div>

    <div class="container">
        <div class="page-header">
            <h2>Onboarding Details for <?php echo htmlspecialchars($client['ClientName']); ?></h2>
            <div style="margin-top: 8px;">
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
            <?php if ($is_other_tech && !$is_temporarily_unlocked): ?>
                <form method="POST" action="" style="margin-top: 12px;">
                    <button type="submit" name="unlock_client" class="btn" onclick="return confirmUnlock();">
                        Unlock Client For Editing
                    </button>
                </form>
            <?php elseif ($is_temporarily_unlocked): ?>
                <p style="margin-top: 10px; color: #155724; font-weight: bold;">
                    This client is unlocked for your current session. You can edit until logout.
                </p>
            <?php endif; ?>
        </div>

        <?php if (isset($folder_message) && $folder_message): ?>
            <div class="message"><?php echo htmlspecialchars($folder_message); ?></div>
        <?php endif; ?>

        <div class="progress-section">
            <h3 style="margin-top: 0; color: #8B4513;">Progress Overview</h3>
            <div class="progress-bar">
                <div id="progress-bar-inner" class="progress-bar-inner" style="width: <?php echo $progress_percentage; ?>%;">
                    <?php echo (int) round($progress_percentage); ?>%
                </div>
            </div>
            <p class="progress-percentage">Progress: <?php echo (int) round($progress_percentage); ?>%</p>
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
                            <input type="date" id="FirstCallout" name="FirstCallout" value="<?php echo isset($details['FirstCallout']) ? htmlspecialchars($details['FirstCallout']) : date('Y-m-d'); ?>" <?php echo isset($details['FirstCallout']) ? 'disabled' : ''; ?> <?php echo $can_edit_client ? '' : 'disabled'; ?>>
                            <?php if (!isset($details['FirstCallout']) && $can_edit_client): ?>
                                <input type="submit" name="update_first_callout" value="Submit">
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="FollowUpCalls">Follow Up Calls</label>
                            <textarea id="FollowUpCalls" name="FollowUpCalls" <?php echo $can_edit_client ? '' : 'disabled'; ?>><?php echo htmlspecialchars($details['FollowUpCalls'] ?? ''); ?></textarea>
                            <?php if ($can_edit_client): ?>
                                <input type="submit" name="update_follow_up_calls" value="Submit">
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="Notes">Notes</label>
                            <textarea id="Notes" name="Notes" <?php echo $can_edit_client ? '' : 'disabled'; ?>><?php echo htmlspecialchars($details['Notes'] ?? ''); ?></textarea>
                            <?php if ($can_edit_client): ?>
                                <input type="submit" name="update_notes" value="Save">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if ($client['ConvertionNeeded'] === 'Yes' && $can_edit_client): ?>
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
                                           <?php echo $can_edit_client ? '' : 'disabled'; ?> 
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
                                    <?php if ($history['ActionType'] !== 'First Callout' && $can_edit_client): ?>
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

        <?php if ($is_temporarily_unlocked): ?>
            <p style="margin-top: 12px; color: #6c757d; font-style: italic;">
                Note: Temporary unlock is active for this browser session and will end when you log out or close the page.
            </p>
        <?php endif; ?>

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
