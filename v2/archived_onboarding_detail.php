<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'settings';

if (!isset($_SESSION['userid']) ||
    ((!isset($_SESSION['department']) || $_SESSION['department'] != 1) &&
    (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin'))) {
    echo "Access denied.";
    exit;
}

$client_id = isset($_GET['client_id']) ? trim((string) $_GET['client_id']) : '';
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;

if ($client_id === '' || $selected_year <= 0) {
    echo "Invalid archive client request.";
    exit;
}

$stmt = $conn->prepare("SELECT o.*, u.FirstName AS TechFirstName, u.LastName AS TechLastName
                        FROM OnboardingArchive o
                        LEFT JOIN Users u ON CAST(o.AssignedTech AS CHAR) = CAST(u.UserID AS CHAR)
                        WHERE o.ClientID = ? AND o.ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    echo "Archived client not found.";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM OnboardingDetailsArchive WHERE ClientID = ? AND ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();
$stmt->close();

$historyOrderClause = 'HistoryID DESC';
$historyColumnCheck = $conn->query("SHOW COLUMNS FROM OnboardingHistoryArchive LIKE 'ActionTimestamp'");
if ($historyColumnCheck && $historyColumnCheck->num_rows > 0) {
    $historyOrderClause = 'ActionTimestamp DESC, HistoryID DESC';
}

$historySql = "SELECT h.*, u.FirstName, u.LastName
               FROM OnboardingHistoryArchive h
               LEFT JOIN Users u ON CAST(h.EditedBy AS CHAR) = CAST(u.UserID AS CHAR)
               WHERE h.ClientID = ? AND h.ArchiveYear = ? ORDER BY {$historyOrderClause}";
$stmt = $conn->prepare($historySql);
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$history_result = $stmt->get_result();
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM EntitledProgramsArchive WHERE ClientID = ? AND ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$entitled_programs = $stmt->get_result()->fetch_assoc();
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
        if ((int) $prog_value === 1 && isset($program_names[$prog_key])) {
            $entitled_program_list[] = $program_names[$prog_key];
        }
    }
}

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

if (($client['BankEnrollment'] ?? 'No') === 'Yes') {
    $checklist_items['Setup']['CompleteBankEnrollment'] = 'Complete Bank Enrollment';
}

$progress_percentage = (int) round((float) ($client['Progress'] ?? 0));
$techName = trim((string) (($client['TechFirstName'] ?? '') . ' ' . ($client['TechLastName'] ?? '')));
if ($techName === '') {
    $techName = (string) ($client['AssignedTech'] ?? 'Unassigned');
}

$upload_token = (string) ($client['UploadToken'] ?? '');
$uploaded_files = [];
if ($upload_token !== '') {
    $upload_folder = __DIR__ . '/data/' . $upload_token;
    if (is_dir($upload_folder)) {
        $uploaded_files = array_values(array_filter(scandir($upload_folder), function ($f) {
            return $f !== '.' && $f !== '..';
        }));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Onboarding Details - <?php echo htmlspecialchars((string) $client['ClientName']); ?></title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body class="page-onboarding-detail">
    <div class="top-menu">
        <ul>
            <li><a href="archived_dashboard.php?year=<?php echo urlencode((string) $selected_year); ?>">← Back to Archived Dashboard</a></li>
            <li><a href="archived_clients.php?year=<?php echo urlencode((string) $selected_year); ?>">Archived Years</a></li>
        </ul>
    </div>

    <div class="container">
        <div class="page-header">
            <h2>Onboarding Details for <?php echo htmlspecialchars((string) $client['ClientName']); ?></h2>
            <div style="margin-top: 8px;">
                <?php if (($client['Spanish'] ?? 'No') === 'Yes'): ?><span class="badge badge-spanish">Spanish Speaker</span><?php endif; ?>
                <?php if (($client['ConvertionNeeded'] ?? 'No') === 'Yes'): ?><span class="badge badge-conversion">Conversion Needed</span><?php endif; ?>
                <?php if (($client['BankEnrollment'] ?? 'No') === 'Yes'): ?><span class="badge badge-bank">Bank Enrollment</span><?php endif; ?>
                <span class="badge" style="margin-left:8px;">Archived Year: <?php echo htmlspecialchars((string) $selected_year); ?></span>
            </div>
            <p style="margin-top: 10px; color: #6c757d; font-style: italic;">Archive records are read-only and do not affect current-year onboarding.</p>
        </div>

        <div class="progress-section">
            <h3 style="margin-top: 0; color: #8B4513;">Progress Overview</h3>
            <div class="progress-bar">
                <div id="progress-bar-inner" class="progress-bar-inner" style="width: <?php echo $progress_percentage; ?>%;"><?php echo $progress_percentage; ?>%</div>
            </div>
            <p class="progress-percentage">Progress: <?php echo $progress_percentage; ?>%</p>
        </div>

        <div class="dual-column">
            <div class="client-info">
                <h3>Client Information</h3>
                <div class="info-grid">
                    <div class="info-item"><strong>Client ID</strong><span class="value"><?php echo htmlspecialchars((string) $client['ClientID']); ?></span></div>
                    <div class="info-item"><strong>Client Name</strong><span class="value"><?php echo htmlspecialchars((string) $client['ClientName']); ?></span></div>
                    <div class="info-item"><strong>Assigned Tech</strong><span class="value"><?php echo htmlspecialchars($techName); ?></span></div>
                    <div class="info-item"><strong>Sales Rep</strong><span class="value"><?php echo htmlspecialchars((string) ($client['SalesRep'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Phone Number</strong><span class="value"><?php echo htmlspecialchars((string) ($client['PhoneNumber'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Email</strong><span class="value"><?php echo htmlspecialchars((string) ($client['Email'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Previous Software</strong><span class="value"><?php echo htmlspecialchars((string) ($client['PreviousSoftware'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Package</strong><span class="value"><?php echo htmlspecialchars((string) ($client['Package'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Ready To Call</strong><span class="value"><?php echo ((int) ($client['ReadyToCall'] ?? 0) === 1) ? 'Yes' : 'No'; ?></span></div>
                    <div class="info-item"><strong>First Callout</strong><span class="value"><?php echo htmlspecialchars((string) ($details['FirstCallout'] ?? '')); ?></span></div>
                    <div class="info-item"><strong>Follow Up Calls</strong><span class="value"><?php echo htmlspecialchars((string) ($details['FollowUpCalls'] ?? '')); ?></span></div>
                </div>

                <div class="entitled-programs">
                    <h3>Entitled Programs</h3>
                    <?php if (empty($entitled_program_list)): ?>
                        <p style="color: #6c757d;">No entitled programs found.</p>
                    <?php else: ?>
                        <ul><?php foreach ($entitled_program_list as $program): ?><li><?php echo htmlspecialchars($program); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label><strong>Internal Notes</strong></label>
                    <textarea rows="5" readonly><?php echo htmlspecialchars((string) ($details['Notes'] ?? '')); ?></textarea>
                </div>

                <?php if ($upload_token !== ''): ?>
                    <div class="upload-link-container">
                        <a href="upload.php?token=<?php echo htmlspecialchars($upload_token); ?>" class="upload-link" target="_blank">Upload Files</a>
                    </div>
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

            <form class="checklist">
                <h3>Onboarding Checklist</h3>
                <?php foreach ($checklist_items as $section => $items): ?>
                    <?php if ($section !== 'Client Data Conversion' || ($client['ConvertionNeeded'] ?? 'No') === 'Yes'): ?>
                        <h4 style="color: #495057; margin-top: 20px; margin-bottom: 10px; border-left: 4px solid #8B4513; padding-left: 10px;"><?php echo htmlspecialchars($section); ?></h4>
                        <ul>
                            <?php foreach ($items as $item => $description): ?>
                                <li>
                                    <input type="checkbox" <?php echo !empty($client[$item]) ? 'checked' : ''; ?> disabled>
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
                        <th style="width: 180px;">Date</th>
                        <th style="width: 120px;">Action</th>
                        <th>Action Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history_result->num_rows > 0): ?>
                        <?php while ($history = $history_result->fetch_assoc()): ?>
                            <?php $historyDate = $history['ActionTimestamp'] ?? $history['Date'] ?? ''; ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $historyDate); ?></td>
                                <td><?php echo htmlspecialchars((string) ($history['ActionType'] ?? '')); ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string) ($history['ActionDetails'] ?? '')); ?>
                                    <?php if (!empty($history['EditedBy']) && (!empty($history['FirstName']) || !empty($history['LastName']))): ?>
                                        <span style="color: #6c757d; font-style: italic; font-size: 12px;">
                                            (Edited by <?php echo htmlspecialchars(trim(($history['FirstName'] ?? '') . ' ' . ($history['LastName'] ?? ''))); ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align: center; color: #6c757d;">No history records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
