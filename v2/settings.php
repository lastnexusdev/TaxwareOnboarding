<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'settings';

// Check if the user is logged in and has a department of 1 (Sales) or is an admin
if (!isset($_SESSION['userid']) || 
    (!isset($_SESSION['department']) || $_SESSION['department'] != 1) && 
    (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')) {
    echo "Access denied.";
    exit;
}

$success_message = '';
$error_message = '';

function get_setting_value(mysqli $conn, string $name, $default = null)
{
    $sql = "SELECT Setting_Value FROM admin_settings WHERE Setting_Name = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['Setting_Value'] ?? $default;
}

function upsert_setting_value(mysqli $conn, string $name, string $value, int $userId): void
{
    $existsSql = "SELECT COUNT(*) as count FROM admin_settings WHERE Setting_Name = ?";
    $stmt = $conn->prepare($existsSql);
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = ((int) $stmt->get_result()->fetch_assoc()['count']) > 0;
    $stmt->close();

    if ($exists) {
        $updateSql = "UPDATE admin_settings SET Setting_Value = ? WHERE Setting_Name = ?";
        $stmt = $conn->prepare($updateSql);
        $stmt->bind_param('ss', $value, $name);
        $stmt->execute();
        $stmt->close();
    } else {
        $insertSql = "INSERT INTO admin_settings (Setting_Name, Setting_Value, UserID) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        $stmt->bind_param('ssi', $name, $value, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function ensure_archive_table(mysqli $conn, string $sourceTable, string $archiveTable): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS `{$archiveTable}` LIKE `{$sourceTable}`");

    $columnCheck = $conn->query("SHOW COLUMNS FROM `{$archiveTable}` LIKE 'ArchiveYear'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `{$archiveTable}` ADD COLUMN ArchiveYear INT NOT NULL");
    }

    $archivedAtCheck = $conn->query("SHOW COLUMNS FROM `{$archiveTable}` LIKE 'ArchivedAt'");
    if ($archivedAtCheck && $archivedAtCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `{$archiveTable}` ADD COLUMN ArchivedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
}

// Fetch current settings
$settings = [];
$settings_sql = "SELECT Setting_Name, Setting_Value FROM admin_settings";
$settings_result = $conn->query($settings_sql);
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['Setting_Name']] = $row['Setting_Value'];
}

$new_software_release = $settings['NewSoftwareRelease'] ?? 0;
$active_tax_year = (int) ($settings['ActiveTaxYear'] ?? date('Y'));

// Handle Year Rollover / Archive
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_year_rollover'])) {
    $next_tax_year = (int) ($_POST['next_tax_year'] ?? ($active_tax_year + 1));

    if ($next_tax_year <= $active_tax_year) {
        $error_message = 'Next tax year must be greater than the current active year.';
    } else {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn->begin_transaction();

            // Ensure archive tables exist with rollover metadata columns
            ensure_archive_table($conn, 'Onboarding', 'OnboardingArchive');
            ensure_archive_table($conn, 'OnboardingDetails', 'OnboardingDetailsArchive');
            ensure_archive_table($conn, 'OnboardingHistory', 'OnboardingHistoryArchive');
            ensure_archive_table($conn, 'EntitledPrograms', 'EntitledProgramsArchive');
            ensure_archive_table($conn, 'Notification', 'NotificationArchive');

            // Archive current year data
            $conn->query("INSERT INTO OnboardingArchive SELECT *, {$active_tax_year} AS ArchiveYear, NOW() AS ArchivedAt FROM Onboarding");
            $conn->query("INSERT INTO OnboardingDetailsArchive SELECT *, {$active_tax_year} AS ArchiveYear, NOW() AS ArchivedAt FROM OnboardingDetails");
            $conn->query("INSERT INTO OnboardingHistoryArchive SELECT *, {$active_tax_year} AS ArchiveYear, NOW() AS ArchivedAt FROM OnboardingHistory");
            $conn->query("INSERT INTO EntitledProgramsArchive SELECT *, {$active_tax_year} AS ArchiveYear, NOW() AS ArchivedAt FROM EntitledPrograms");
            $conn->query("INSERT INTO NotificationArchive SELECT *, {$active_tax_year} AS ArchiveYear, NOW() AS ArchivedAt FROM Notification");

            // Clear active-year grids/tables for new year start
            $conn->query("DELETE FROM Notification");
            $conn->query("DELETE FROM OnboardingHistory");
            $conn->query("DELETE FROM OnboardingDetails");
            $conn->query("DELETE FROM EntitledPrograms");
            $conn->query("DELETE FROM Onboarding");

            upsert_setting_value($conn, 'ActiveTaxYear', (string) $next_tax_year, (int) $_SESSION['userid']);

            $conn->commit();
            $success_message = "Year rollover completed. Archived {$active_tax_year} clients and switched active year to {$next_tax_year}.";
            $active_tax_year = $next_tax_year;
        } catch (Throwable $e) {
            $conn->rollback();
            $error_message = 'Year rollover failed: ' . $e->getMessage();
        }
    }
}

// Fetch all software programs
$programs_sql = "SHOW COLUMNS FROM EntitledPrograms LIKE 'prog_%'";
$programs_result = $conn->query($programs_sql);
$available_programs = [];
while ($row = $programs_result->fetch_assoc()) {
    $program_key = $row['Field'];
    $program_name = str_replace(['prog_', '_'], ['', ' '], $program_key);
    $available_programs[$program_key] = $program_name;
}

// Fetch custom packages
$packages_sql = "SELECT * FROM CustomPackages ORDER BY PackageName";
$packages_result = $conn->query($packages_sql);
$custom_packages = [];
if ($packages_result) {
    while ($row = $packages_result->fetch_assoc()) {
        $custom_packages[] = $row;
    }
}

// Handle New Software Release Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_software_release'])) {
    $new_software_release = isset($_POST['new_software_release']) ? intval($_POST['new_software_release']) : 0;

    // Check if the setting exists
    $check_setting_sql = "SELECT COUNT(*) as count FROM admin_settings WHERE Setting_Name = 'NewSoftwareRelease'";
    $check_setting_result = $conn->query($check_setting_sql);
    $setting_exists = $check_setting_result->fetch_assoc()['count'] > 0;

    if ($setting_exists) {
        $update_setting_sql = "UPDATE admin_settings SET Setting_Value = ? WHERE Setting_Name = 'NewSoftwareRelease'";
        $stmt = $conn->prepare($update_setting_sql);
        $stmt->bind_param('i', $new_software_release);
        $stmt->execute();
        $stmt->close();
    } else {
        $insert_setting_sql = "INSERT INTO admin_settings (Setting_Name, Setting_Value, UserID) VALUES ('NewSoftwareRelease', ?, ?)";
        $stmt = $conn->prepare($insert_setting_sql);
        $stmt->bind_param('ii', $new_software_release, $_SESSION['userid']);
        $stmt->execute();
        $stmt->close();
    }

    // Update progress for all clients
    $clients_sql = "SELECT ClientID, ConvertionNeeded, BankEnrollment FROM Onboarding";
    $clients_result = $conn->query($clients_sql);
    
    while ($client = $clients_result->fetch_assoc()) {
        $client_id = $client['ClientID'];
        
        // Fetch client checklist status
        $client_sql = "SELECT * FROM Onboarding WHERE ClientID = ?";
        $stmt = $conn->prepare($client_sql);
        $stmt->bind_param('s', $client_id);
        $stmt->execute();
        $client_result = $stmt->get_result();
        $client_data = $client_result->fetch_assoc();
        $stmt->close();

        // Define base checklist items
        $checklist_items = [
            'ConfirmContactInfo', 'ReviewRequirements', 'ScheduleAppointment',
            'DownloadSoftware', 'InformClient', 'StartInstallation',
            'EnterUserID', 'ConfigureSettings', 'ManageUserAccounts', 'RunSoftware',
            'ProvideWalkthrough', 'DemonstrateTasks', 'ContactSupport', 'OfferResources', 
            'ProvideTrainingInfo', 'ScheduleFollowUp'
        ];

        if ($client['ConvertionNeeded'] === 'Yes') {
            $checklist_items = array_merge($checklist_items, ['VerifyPlanData', 'ExecuteConversion', 'VerifyIntegrity', 'TransferSetupData']);
        }

        if ($client['BankEnrollment'] === 'Yes') {
            $checklist_items[] = 'CompleteBankEnrollment';
        }

        if ($new_software_release == 1) {
            $checklist_items[] = 'InstalledNewVersion';
        }

        // Calculate progress
        $total_items = count($checklist_items);
        $completed_items = 0;

        foreach ($checklist_items as $item) {
            if (isset($client_data[$item]) && $client_data[$item]) {
                $completed_items++;
            }
        }

        $progress_percentage = ($completed_items / $total_items) * 100;

        // Update progress
        $update_progress_sql = "UPDATE Onboarding SET Progress = ? WHERE ClientID = ?";
        $stmt = $conn->prepare($update_progress_sql);
        $stmt->bind_param('ds', $progress_percentage, $client_id);
        $stmt->execute();
        $stmt->close();
    }

    $success_message = "Software release setting updated successfully! Client progress recalculated.";
}

// Handle Add Custom Package
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_package'])) {
    $package_name = trim($_POST['package_name']);
    $package_description = trim($_POST['package_description']);
    $selected_programs = $_POST['package_programs'] ?? [];

    if (!empty($package_name) && !empty($selected_programs)) {
        // Create programs JSON
        $programs_json = json_encode($selected_programs);
        
        $insert_package_sql = "INSERT INTO CustomPackages (PackageName, PackageDescription, Programs) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert_package_sql);
        $stmt->bind_param('sss', $package_name, $package_description, $programs_json);
        
        if ($stmt->execute()) {
            $success_message = "Custom package '{$package_name}' created successfully!";
        } else {
            $error_message = "Error creating package: " . $conn->error;
        }
        $stmt->close();
        
        // Refresh packages list
        header("Location: settings.php?success=package_added");
        exit;
    } else {
        $error_message = "Package name and at least one program are required.";
    }
}

// Handle Delete Custom Package
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_package'])) {
    $package_id = intval($_POST['package_id']);
    
    $delete_sql = "DELETE FROM CustomPackages WHERE PackageID = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param('i', $package_id);
    
    if ($stmt->execute()) {
        $success_message = "Package deleted successfully!";
    } else {
        $error_message = "Error deleting package: " . $conn->error;
    }
    $stmt->close();
    
    header("Location: settings.php?success=package_deleted");
    exit;
}

// Handle Add New Program
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_program'])) {
    $program_name = trim($_POST['program_name']);
    $program_key = 'prog_' . str_replace(' ', '', $program_name);
    
    if (!empty($program_name)) {
        // Check if column already exists
        $check_column_sql = "SHOW COLUMNS FROM EntitledPrograms LIKE '$program_key'";
        $check_result = $conn->query($check_column_sql);
        
        if ($check_result->num_rows == 0) {
            // Add new column to EntitledPrograms
            $alter_sql = "ALTER TABLE EntitledPrograms ADD COLUMN $program_key TINYINT(1) NOT NULL DEFAULT 0";
            
            if ($conn->query($alter_sql)) {
                $success_message = "Program '{$program_name}' added successfully!";
                header("Location: settings.php?success=program_added");
                exit;
            } else {
                $error_message = "Error adding program: " . $conn->error;
            }
        } else {
            $error_message = "A program with this name already exists.";
        }
    } else {
        $error_message = "Program name is required.";
    }
}

// Handle System Settings Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_system_settings'])) {
    $default_ready_to_call = isset($_POST['default_ready_to_call']) ? 1 : 0;
    $auto_assign_techs = isset($_POST['auto_assign_techs']) ? 1 : 0;
    $require_bank_enrollment = isset($_POST['require_bank_enrollment']) ? 1 : 0;
    
    // Update or insert settings
    $system_settings = [
        'DefaultReadyToCall' => $default_ready_to_call,
        'AutoAssignTechs' => $auto_assign_techs,
        'RequireBankEnrollment' => $require_bank_enrollment
    ];
    
    foreach ($system_settings as $setting_name => $setting_value) {
        $check_sql = "SELECT COUNT(*) as count FROM admin_settings WHERE Setting_Name = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param('s', $setting_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        $stmt->close();
        
        if ($exists) {
            $update_sql = "UPDATE admin_settings SET Setting_Value = ? WHERE Setting_Name = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param('is', $setting_value, $setting_name);
            $stmt->execute();
            $stmt->close();
        } else {
            $insert_sql = "INSERT INTO admin_settings (Setting_Name, Setting_Value, UserID) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param('sii', $setting_name, $setting_value, $_SESSION['userid']);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    $success_message = "System settings updated successfully!";
}

// Check for success messages from redirects
if (isset($_GET['success'])) {
    switch($_GET['success']) {
        case 'package_added':
            $success_message = "Custom package created successfully!";
            break;
        case 'package_deleted':
            $success_message = "Package deleted successfully!";
            break;
        case 'program_added':
            $success_message = "Program added successfully!";
            break;
    }
}

// Fetch system settings
$default_ready_to_call = $settings['DefaultReadyToCall'] ?? 1;
$auto_assign_techs = $settings['AutoAssignTechs'] ?? 1;
$require_bank_enrollment = $settings['RequireBankEnrollment'] ?? 0;

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script>
        function confirmDelete(packageName) {
            return confirm('Are you sure you want to delete the package "' + packageName + '"?\n\nThis action cannot be undone.');
        }
    </script>
</head>
<body class="page-settings">
<?php include 'includes/header.php'; ?>
    <div class="settings-container">
        <h2>System Settings</h2>

        <?php if ($success_message): ?>
            <div class="success-message">
                ? <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="error-message">
                ? <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="settings-grid">
            <!-- Software Release Settings -->
            <div class="settings-card">
                <h3><span class="icon" aria-hidden="true">🛠️</span> Software Release</h3>
                
                <div class="info-banner">
                    <h4>About Software Releases</h4>
                    <p>When a new version is released, this setting triggers an additional checklist item for all clients. Progress percentages will be recalculated automatically.</p>
                </div>

                <form method="POST" action="">
                    <div class="toggle-switch">
                        <div>
                            <label for="new_software_release">New Software Release</label>
                            <div class="description">Enable when a new version of the software is released</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="new_software_release" name="new_software_release" value="1" <?php echo $new_software_release == 1 ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <button type="submit" name="update_software_release" class="btn-primary">
                        Update Software Release Setting
                    </button>
                </form>
            </div>

            <!-- System Preferences -->
            <div class="settings-card">
                <h3><span class="icon" aria-hidden="true">⚙️</span> System Preferences</h3>

                <form method="POST" action="">
                    <div class="toggle-switch">
                        <div>
                            <label for="default_ready_to_call">Default Ready to Call</label>
                            <div class="description">Auto-check "Ready to Call" when adding new clients</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="default_ready_to_call" name="default_ready_to_call" value="1" <?php echo $default_ready_to_call == 1 ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-switch">
                        <div>
                            <label for="auto_assign_techs">Auto-Assign Techs</label>
                            <div class="description">Automatically assign techs using round-robin</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="auto_assign_techs" name="auto_assign_techs" value="1" <?php echo $auto_assign_techs == 1 ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-switch">
                        <div>
                            <label for="require_bank_enrollment">Require Bank Enrollment</label>
                            <div class="description">Make bank enrollment mandatory for all clients</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="require_bank_enrollment" name="require_bank_enrollment" value="1" <?php echo $require_bank_enrollment == 1 ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <button type="submit" name="update_system_settings" class="btn-primary">
                        Update System Preferences
                    </button>
                </form>
            </div>

            <!-- Year Rollover / Archive -->
            <div class="settings-card">
                <h3><span class="icon" aria-hidden="true">🗂️</span> Year Rollover</h3>

                <div class="warning-banner">
                    <h4>Archive Current Year and Start New Year</h4>
                    <p>This archives all active clients and related records, clears current onboarding grids, and moves the system into the next tax year.</p>
                </div>

                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to archive the current year and start a new year? This will clear active client grids.');">
                    <div class="form-group">
                        <label>Current Active Tax Year</label>
                        <input type="text" value="<?php echo htmlspecialchars((string) $active_tax_year); ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label for="next_tax_year">Next Tax Year</label>
                        <input type="number" id="next_tax_year" name="next_tax_year" value="<?php echo htmlspecialchars((string) ($active_tax_year + 1)); ?>" min="<?php echo htmlspecialchars((string) ($active_tax_year + 1)); ?>" required>
                    </div>

                    <button type="submit" name="run_year_rollover" class="btn-primary">Run Year Rollover</button>
                    <a href="archived_clients.php" class="btn-secondary" style="display:inline-block; margin-top:10px; text-align:center; width:100%; box-sizing:border-box; text-decoration:none;">View Archived Clients</a>
                </form>
            </div>
        </div>

        <div class="settings-grid">
            <!-- Add New Program -->
            <div class="settings-card">
                <h3><span class="icon" aria-hidden="true">➕</span> Add New Program</h3>

                <div class="info-banner">
                    <h4>Adding Programs</h4>
                    <p>Add new software programs that can be assigned to clients. Programs will be available when creating packages.</p>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="program_name">Program Name</label>
                        <input type="text" id="program_name" name="program_name" placeholder="e.g., 1120 or Payroll Manager" required>
                    </div>

                    <button type="submit" name="add_program" class="btn-primary">
                        Add Program
                    </button>
                </form>

                <div class="packages-list" style="margin-top: 20px;">
                    <h4 style="margin-bottom: 15px; color: #555;">Available Programs (<?php echo count($available_programs); ?>)</h4>
                    <div class="package-programs">
                        <?php foreach ($available_programs as $key => $name): ?>
                            <span class="program-badge"><?php echo htmlspecialchars($name); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Create Custom Package -->
            <div class="settings-card">
                <h3><span class="icon" aria-hidden="true">📦</span> Create Custom Package</h3>

                <div class="info-banner">
                    <h4>Custom Packages</h4>
                    <p>Create reusable package templates with specific program combinations for faster client setup.</p>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="package_name">Package Name</label>
                        <input type="text" id="package_name" name="package_name" placeholder="e.g., Premium Tax Package" required>
                    </div>

                    <div class="form-group">
                        <label for="package_description">Description (Optional)</label>
                        <textarea id="package_description" name="package_description" placeholder="Brief description of what this package includes..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Select Programs</label>
                        <div class="checkbox-grid">
                            <?php foreach ($available_programs as $prog_key => $prog_name): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="<?php echo $prog_key; ?>" name="package_programs[]" value="<?php echo $prog_key; ?>">
                                    <label for="<?php echo $prog_key; ?>"><?php echo htmlspecialchars($prog_name); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" name="add_package" class="btn-primary">
                        Create Package
                    </button>
                </form>
            </div>
        </div>

        <!-- Existing Custom Packages -->
        <div class="settings-card" style="grid-column: 1 / -1;">
            <h3><span class="icon" aria-hidden="true">📚</span> Custom Packages</h3>

            <?php if (count($custom_packages) > 0): ?>
                <div class="packages-list">
                    <?php foreach ($custom_packages as $package): ?>
                        <?php
                        $programs = json_decode($package['Programs'], true);
                        ?>
                        <div class="package-item">
                            <h4><?php echo htmlspecialchars($package['PackageName']); ?></h4>
                            <?php if (!empty($package['PackageDescription'])): ?>
                                <p><?php echo htmlspecialchars($package['PackageDescription']); ?></p>
                            <?php endif; ?>
                            
                            <div class="package-programs">
                                <?php foreach ($programs as $prog_key): ?>
                                    <?php if (isset($available_programs[$prog_key])): ?>
                                        <span class="program-badge"><?php echo htmlspecialchars($available_programs[$prog_key]); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <form method="POST" action="" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($package['PackageName']); ?>');">
                                <input type="hidden" name="package_id" value="<?php echo $package['PackageID']; ?>"><button type="submit" name="delete_package" class="btn-danger">
                                    Delete Package
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-packages">
                    <h4>No Custom Packages</h4>
                    <p>Create your first custom package above to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
