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

// Fetch current settings
$settings = [];
$settings_sql = "SELECT Setting_Name, Setting_Value FROM admin_settings";
$settings_result = $conn->query($settings_sql);
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['Setting_Name']] = $row['Setting_Value'];
}

$new_software_release = $settings['NewSoftwareRelease'] ?? 0;

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
    <style>
        body {
            background-color: #f4f4f4;
        }

        .settings-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 700;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #28a745;
            margin-bottom: 20px;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #dc3545;
            margin-bottom: 20px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .settings-card {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .settings-card h3 {
            margin-top: 0;
            color: #007BFF;
            border-bottom: 2px solid #007BFF;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .settings-card h3 .icon {
            font-size: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007BFF;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .toggle-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .toggle-switch label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
            flex: 1;
        }

        .toggle-switch .description {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #28a745;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #007BFF 0%, #0056b3 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.2);
            width: 100%;
            margin-top: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 123, 255, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .checkbox-item:hover {
            background-color: #e9ecef;
        }

        .checkbox-item input[type="checkbox"] {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .packages-list {
            margin-top: 20px;
        }

        .package-item {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid #007BFF;
        }

        .package-item h4 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 16px;
        }

        .package-item p {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
        }

        .package-programs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .program-badge {
            background-color: #007BFF;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .no-packages {
            text-align: center;
            padding: 40px;
            color: #999;
            background-color: #f8f9fa;
            border-radius: 6px;
        }

        .info-banner {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #007BFF;
            margin-bottom: 20px;
        }

        .info-banner h4 {
            margin: 0 0 8px 0;
            color: #0d47a1;
            font-size: 16px;
        }

        .info-banner p {
            margin: 0;
            color: #01579b;
            font-size: 14px;
        }

        .warning-banner {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #ffc107;
            margin-bottom: 20px;
        }

        .warning-banner h4 {
            margin: 0 0 8px 0;
            color: #856404;
            font-size: 16px;
        }

        .warning-banner p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }

        @media (max-width: 968px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
        function confirmDelete(packageName) {
            return confirm('Are you sure you want to delete the package "' + packageName + '"?\n\nThis action cannot be undone.');
        }
    </script>
</head>
<body>
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
                <h3><span class="icon">??</span> Software Release</h3>
                
                <div class="info-banner">
                    <h4>?? About Software Releases</h4>
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
                <h3><span class="icon">??</span> System Preferences</h3>

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
        </div>

        <div class="settings-grid">
            <!-- Add New Program -->
            <div class="settings-card">
                <h3><span class="icon">?</span> Add New Program</h3>

                <div class="info-banner">
                    <h4>?? Adding Programs</h4>
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
                <h3><span class="icon">??</span> Create Custom Package</h3>

                <div class="info-banner">
                    <h4>?? Custom Packages</h4>
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
            <h3><span class="icon">??</span> Custom Packages</h3>

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
                                    ??? Delete Package
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