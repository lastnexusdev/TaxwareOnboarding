<?php
session_start();
$requireRoles = ['admin', 'sales']; // only admins and sales
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'sales';

// Ensure user session exists
if (!isset($_SESSION['userid'])) {
    echo "Access denied: not logged in properly.";
    exit;
}

$user_id = (int) $_SESSION['userid'];

// Fetch the logged-in user's first name safely
$user_first_name = "Unknown";
$stmt = $conn->prepare("SELECT FirstName FROM Users WHERE UserID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $user_first_name = $row['FirstName'];
}
$stmt->close();

// Fetch techs with department 2
$techs = [];
$techs_sql = "SELECT UserID, FirstName, LastName, Spanish FROM Users WHERE Department = 2";
if ($techs_result = $conn->query($techs_sql)) {
    while ($row = $techs_result->fetch_assoc()) {
        $techs[] = $row;
    }
}

// Fetch sales reps with department 1
$sales_reps = [];
$sales_sql = "SELECT UserID, FirstName, LastName FROM Users WHERE Department = 1";
if ($sales_result = $conn->query($sales_sql)) {
    while ($row = $sales_result->fetch_assoc()) {
        $sales_reps[] = $row;
    }
}

// Get the last assigned tech
$last_assigned_tech = null;
$last_sql = "SELECT UserID FROM LastAssignedTech ORDER BY id DESC LIMIT 1";
if ($last_res = $conn->query($last_sql)) {
    if ($last_res->num_rows > 0) {
        $last_row = $last_res->fetch_assoc();
        $last_assigned_tech = $last_row['UserID'];
    }
}

// Find the next tech to assign
$next_tech_index = 0;
if ($last_assigned_tech !== null && !empty($techs)) {
    foreach ($techs as $index => $tech) {
        if ($tech['UserID'] == $last_assigned_tech) {
            $next_tech_index = ($index + 1) % count($techs);
            break;
        }
    }
}
$next_tech = $techs[$next_tech_index]['UserID'] ?? null;

// Fetch system settings
$default_ready_to_call = 1; // Default value
$setting_sql = "SELECT Setting_Value FROM admin_settings WHERE Setting_Name = 'DefaultReadyToCall'";
$setting_result = $conn->query($setting_sql);
if ($setting_result && $setting_result->num_rows > 0) {
    $setting_row = $setting_result->fetch_assoc();
    $default_ready_to_call = intval($setting_row['Setting_Value']);
}

// Fetch custom packages
$custom_packages = [];
$packages_sql = "SELECT * FROM CustomPackages ORDER BY PackageName";
$packages_result = $conn->query($packages_sql);
if ($packages_result) {
    while ($row = $packages_result->fetch_assoc()) {
        $custom_packages[] = $row;
    }
}

// Success message variable
$success_message = '';

// Handle form submission for adding clients
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_client'])) {
    $date_added       = $_POST['date_added'] ?? date('Y-m-d');
    $client_id        = $_POST['client_id'] ?? '';
    $client_name      = $_POST['client_name'] ?? '';
    $assigned_tech    = $_POST['assigned_tech'] ?? '';
    $sales_rep        = $_POST['sales_rep'] ?? '';
    $email            = $_POST['email'] ?? '';
    $phone_number     = $_POST['phone_number'] ?? '';
    $previous_software= $_POST['previous_software'] ?? '';

    // Handle "Other" software properly
    if ($previous_software === 'Other') {
        $previous_software = trim($_POST['other_software'] ?? '');
        if ($previous_software === '') {
            $previous_software = "Other (unspecified)";
        }
    }

    $conversion_needed= $_POST['conversion_needed'] ?? 'No';
    $spanish          = $_POST['spanish'] ?? 'No';
    $bank_enrollment  = $_POST['bank_enrollment'] ?? 'No';
    $package          = $_POST['package'] ?? '';
    $ready_to_call    = isset($_POST['ready_to_call']) ? 1 : 0;
    $notes            = $_POST['notes'] ?? '';

    // Generate upload token
    $upload_token = bin2hex(random_bytes(16));

    // Initialize entitled programs
    $entitled_programs = [
        'prog_1040' => 0,
        'prog_Depreciation' => 0,
        'prog_Proforma' => 0,
        'prog_1120' => 0,
        'prog_1120S' => 0,
        'prog_1065' => 0,
        'prog_1041' => 0,
        'prog_706Estate' => 0,
        'prog_709Gift' => 0,
        'prog_990Exempt' => 0,
        'prog_DocArk' => 0,
        'prog_1099Acc' => 0,
    ];

    // Check if it's a custom package
    $is_custom_package = false;
    foreach ($custom_packages as $custom_pkg) {
        if ($package === $custom_pkg['PackageName']) {
            $is_custom_package = true;
            $custom_programs = json_decode($custom_pkg['Programs'], true);
            foreach ($custom_programs as $prog_key) {
                if (isset($entitled_programs[$prog_key])) {
                    $entitled_programs[$prog_key] = 1;
                }
            }
            break;
        }
    }

    // If not custom package, check standard packages
    if (!$is_custom_package) {
        if ($package === 'Custom Package' && !empty($_POST['custom_programs']) && is_array($_POST['custom_programs'])) {
            foreach ($_POST['custom_programs'] as $program) {
                if (isset($entitled_programs[$program])) {
                    $entitled_programs[$program] = 1;
                }
            }
        } else {
            switch ($package) {
                case 'Individual Package':
                    $entitled_programs['prog_1040'] = 1;
                    $entitled_programs['prog_Depreciation'] = 1;
                    $entitled_programs['prog_Proforma'] = 1;
                    break;
                case 'Business Package':
                    $entitled_programs['prog_1040'] = 1;
                    $entitled_programs['prog_Depreciation'] = 1;
                    $entitled_programs['prog_Proforma'] = 1;
                    $entitled_programs['prog_1120'] = 1;
                    $entitled_programs['prog_1120S'] = 1;
                    $entitled_programs['prog_1065'] = 1;
                    $entitled_programs['prog_1041'] = 1;
                    break;
                case 'Professional Package':
                    foreach ($entitled_programs as $key => $val) {
                        $entitled_programs[$key] = 1;
                    }
                    break;
            }
        }
    }

    if ($package === 'Custom Package' && array_sum($entitled_programs) === 0) {
        $error_message = "Please select at least one program for a one-time custom package.";
    }

    // Insert client into Onboarding
    if (empty($error_message)) {
    $sql = "INSERT INTO Onboarding 
        (DateAdded, ClientID, ClientName, AssignedTech, SalesRep, Email, PhoneNumber, PreviousSoftware, ConvertionNeeded, Spanish, BankEnrollment, Package, ReadyToCall, UploadToken) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssssssssssis",
        $date_added,
        $client_id,
        $client_name,
        $assigned_tech,
        $sales_rep,
        $email,
        $phone_number,
        $previous_software,
        $conversion_needed,
        $spanish,
        $bank_enrollment,
        $package,
        $ready_to_call,
        $upload_token
    );
    if ($stmt->execute()) {
        // Insert entitled programs
        $ent_sql = "INSERT INTO EntitledPrograms 
            (ClientID, prog_1040, prog_Depreciation, prog_Proforma, prog_1120, prog_1120S, prog_1065, prog_1041, prog_706Estate, prog_709Gift, prog_990Exempt, prog_DocArk, prog_1099Acc) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $ent_stmt = $conn->prepare($ent_sql);
        $ent_stmt->bind_param(
            "siiiiiiiiiiii",
            $client_id,
            $entitled_programs['prog_1040'],
            $entitled_programs['prog_Depreciation'],
            $entitled_programs['prog_Proforma'],
            $entitled_programs['prog_1120'],
            $entitled_programs['prog_1120S'],
            $entitled_programs['prog_1065'],
            $entitled_programs['prog_1041'],
            $entitled_programs['prog_706Estate'],
            $entitled_programs['prog_709Gift'],
            $entitled_programs['prog_990Exempt'],
            $entitled_programs['prog_DocArk'],
            $entitled_programs['prog_1099Acc']
        );
        $ent_stmt->execute();
        $ent_stmt->close();

        // Insert notes
        $note_stmt = $conn->prepare("INSERT INTO OnboardingDetails (ClientID, Notes) VALUES (?, ?)");
        $note_stmt->bind_param("ss", $client_id, $notes);
        $note_stmt->execute();
        $note_stmt->close();

        // Insert notification
        $notif_stmt = $conn->prepare("INSERT INTO Notification (ClientID, TechID, Message, Date) VALUES (?, ?, 'New client assigned to you.', NOW())");
        $notif_stmt->bind_param("ss", $client_id, $assigned_tech);
        $notif_stmt->execute();
        $notif_stmt->close();

        // Update last assigned tech
        $last_stmt = $conn->prepare("INSERT INTO LastAssignedTech (UserID) VALUES (?)");
        $last_stmt->bind_param("s", $assigned_tech);
        $last_stmt->execute();
        $last_stmt->close();

        $success_message = "Client '{$client_name}' added successfully!";
        
        // Clear form by redirecting
        header("Location: sales.php?success=1");
        exit;
    } else {
        $error_message = "Error inserting client: " . $conn->error;
    }
    $stmt->close();
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = "New client added successfully!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Dashboard</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script>
    function formatPhoneNumber(value) {
        // Remove all non-digit characters
        const cleaned = ('' + value).replace(/\D/g, '');

        // Break into parts: (area) central-line
        const match = cleaned.match(/^(\d{0,3})(\d{0,3})(\d{0,4})$/);
        if (match) {
            let formatted = '';
            if (match[1]) {
                formatted = '(' + match[1];
            }
            if (match[2]) {
                formatted += (match[1].length === 3 ? ') ' : '') + match[2];
            }
            if (match[3]) {
                formatted += (match[2].length === 3 ? '-' : '') + match[3];
            }
            return formatted;
        }
        return value;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const phoneInput = document.getElementById('phone_number');
        phoneInput.addEventListener('input', function(e) {
            const cursorPos = e.target.selectionStart;
            const beforeLength = e.target.value.length;

            e.target.value = formatPhoneNumber(e.target.value);

            // Try to maintain cursor position
            const afterLength = e.target.value.length;
            e.target.selectionEnd = cursorPos + (afterLength - beforeLength);
        });
    });
</script>

<script>
        // Store custom packages data for JavaScript access
        var customPackages = <?php echo json_encode($custom_packages); ?>;

        function checkPreviousSoftware() {
            var previousSoftware = document.getElementById('previous_software').value;
            if (previousSoftware === 'Other') {
                document.getElementById('other_software_modal').style.display = 'block';
                document.getElementById('conversion_needed').value = 'No';
                document.getElementById('conversion_needed').disabled = true;
            } else {
                document.getElementById('other_software_modal').style.display = 'none';
                document.getElementById('conversion_needed').disabled = false;
            }
        }

        function closeModal() {
            document.getElementById('other_software_modal').style.display = 'none';
            document.getElementById('custom_package_modal').style.display = 'none';
            syncCustomPackageSelections();
        }

        function syncCustomPackageSelections() {
            var hiddenContainer = document.getElementById('custom_programs_hidden_container');
            if (!hiddenContainer) {
                return;
            }

            hiddenContainer.innerHTML = '';

            var checkedPrograms = document.querySelectorAll('#custom_package_modal input[name="custom_programs[]"]:checked');
            checkedPrograms.forEach(function(checkbox) {
                var hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'custom_programs[]';
                hiddenInput.value = checkbox.value;
                hiddenContainer.appendChild(hiddenInput);
            });
        }

        function confirmConversionChange() {
            var conversionNeeded = document.getElementById('conversion_needed').value;
            if (conversionNeeded === 'Yes' && document.getElementById('previous_software').value === 'Other') {
                var confirmChange = confirm("Conversion is not possible with Other software. Are you sure you want to change it to Yes?");
                if (!confirmChange) {
                    document.getElementById('conversion_needed').value = 'No';
                }
            }
        }

        function submitOtherSoftware() {
    var otherSoftware = document.getElementById('other_software').value.trim();
    if (otherSoftware === '') {
        alert('Please enter a software name');
        return;
    }

    // Keep the dropdown at "Other"
    document.getElementById('previous_software').value = 'Other';

    // Copy user text into hidden field (so PHP sees it on POST)
    document.getElementById('other_software_hidden').value = otherSoftware;

    closeModal();
}

        function updateAssignedTechs() {
            var spanishRequired = document.getElementById('spanish').value;
            var techs = <?php echo json_encode($techs); ?>;
            var assignedTechSelect = document.getElementById('assigned_tech');

            assignedTechSelect.innerHTML = '';

            var techsFiltered = techs.filter(function(tech) {
                return spanishRequired === 'No' || (spanishRequired === 'Yes' && tech.Spanish == 1);
            });

            if (techsFiltered.length === 0) {
                var option = document.createElement('option');
                option.value = '';
                option.text = 'No Spanish-speaking techs available';
                assignedTechSelect.appendChild(option);
                return;
            }

            for (var i = 0; i < techsFiltered.length; i++) {
                var option = document.createElement('option');
                option.value = techsFiltered[i].UserID;
                option.text = techsFiltered[i].FirstName + ' ' + techsFiltered[i].LastName;
                if (techsFiltered[i].UserID == '<?php echo $next_tech; ?>') {
                    option.selected = true;
                }
                assignedTechSelect.appendChild(option);
            }
        }

        function checkPackage() {
            var packageSelect = document.getElementById('package');
            var selectedPackage = packageSelect.value;
            
            // Check if it's a custom package
            var isCustomPackage = false;
            for (var i = 0; i < customPackages.length; i++) {
                if (customPackages[i].PackageName === selectedPackage) {
                    isCustomPackage = true;
                    break;
                }
            }
            
            // Only show modal if "Custom Package" is selected (not a saved custom package)
            if (selectedPackage === 'Custom Package' && !isCustomPackage) {
                document.getElementById('custom_package_modal').style.display = 'block';
            } else {
                document.getElementById('custom_package_modal').style.display = 'none';
            }

            syncCustomPackageSelections();
        }

        document.addEventListener('DOMContentLoaded', function() {
            var addClientForm = document.querySelector('form[method="POST"]');
            var packageSelect = document.getElementById('package');

            var customProgramCheckboxes = document.querySelectorAll('#custom_package_modal input[name="custom_programs[]"]');
            customProgramCheckboxes.forEach(function(checkbox) {
                checkbox.addEventListener('change', syncCustomPackageSelections);
            });

            if (addClientForm) {
                addClientForm.addEventListener('submit', function(event) {
                    syncCustomPackageSelections();

                    if (packageSelect && packageSelect.value === 'Custom Package') {
                        var selectedCount = document.querySelectorAll('#custom_package_modal input[name="custom_programs[]"]:checked').length;
                        if (selectedCount === 0) {
                            event.preventDefault();
                            alert('Please select at least one program for the one-time custom package.');
                            document.getElementById('custom_package_modal').style.display = 'block';
                        }
                    }
                });
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            var otherModal = document.getElementById('other_software_modal');
            var customModal = document.getElementById('custom_package_modal');
            if (event.target == otherModal) {
                closeModal();
            }
            if (event.target == customModal) {
                closeModal();
            }
        }
    </script>
    <link rel="stylesheet" type="text/css" href="css/sales.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
    <div class="sales-container">
        <h2>Add New Client</h2>

        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                ? <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                ? <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="add-client-section">
            <h3>Client Information</h3>
            
            <form method="POST" action="">
                <!-- Basic Information Section -->
                <div class="form-section-title">Basic Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="date_added">Date Added</label>
                        <input type="date" id="date_added" name="date_added" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="client_id">Client ID</label>
                        <input type="text" id="client_id" name="client_id" required placeholder="Enter unique client ID">
                        <small>Unique identifier for the client</small>
                    </div>

                    <div class="form-group">
                        <label for="client_name">Client Name</label>
                        <input type="text" id="client_name" name="client_name" required placeholder="Enter client name">
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required placeholder="client@example.com">
                    </div>

                    <div class="form-group">
                        <label for="phone_number">Phone Number</label>
                        <input type="text" id="phone_number" name="phone_number" required placeholder="(555) 123-4567">
                    </div>

		<div class="form-group">
                        <label for="sales_rep">Sales Rep</label>
                        <select id="sales_rep" name="sales_rep" required>
                            <?php foreach ($sales_reps as $rep): ?>
                                <option value="<?php echo htmlspecialchars($rep['FirstName']); ?>" <?php echo $rep['UserID'] == $user_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rep['FirstName'] . ' ' . $rep['LastName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Defaults to you but can be changed</small>
                    </div>
			<input type="hidden" id="other_software_hidden" name="other_software" value="">

                </div>

                <!-- Assignment Section -->
                <div class="form-section-title">Tech Assignment</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="spanish">Spanish Required</label>
                        <select id="spanish" name="spanish" onchange="updateAssignedTechs()" required>
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                        <small>Does the client require Spanish-speaking support?</small>
                    </div>

                    <div class="form-group">
                        <label for="assigned_tech">Assigned Tech</label>
                        <select id="assigned_tech" name="assigned_tech" required>
                            <?php foreach ($techs as $tech): ?>
                                <option value="<?php echo htmlspecialchars($tech['UserID']); ?>" <?php echo $tech['UserID'] == $next_tech ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tech['FirstName'] . ' ' . $tech['LastName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Auto-assigned using round-robin</small>
                    </div>
                </div>

                <!-- Software & Conversion Section -->
                <div class="form-section-title">Software & Conversion</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="previous_software">Previous Software</label>
                        <select id="previous_software" name="previous_software" onchange="checkPreviousSoftware()" required>
                            <option value="ATX">ATX</option>
                            <option value="Crosslink">Crosslink</option>
                            <option value="Drake">Drake</option>
                            <option value="Lacerte">Lacerte</option>
                            <option value="ProSeries">ProSeries</option>
                            <option value="ProSystem">ProSystem</option>
                            <option value="TaxAct">TaxAct</option>
                            <option value="TaxSlayer">TaxSlayer</option>
                            <option value="TaxWise">TaxWise</option>
                            <option value="Taxware">Taxware</option>
                            <option value="UltraTax">UltraTax</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="conversion_needed">Conversion Needed</label>
                        <select id="conversion_needed" name="conversion_needed" onchange="confirmConversionChange()" required>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                        <small>Does client data need to be converted?</small>
                    </div>

                    <div class="form-group">
                        <label for="bank_enrollment">Bank Enrollment</label>
                        <select id="bank_enrollment" name="bank_enrollment" required>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                        <small>Does client need bank enrollment?</small>
                    </div>

                    <div class="form-group">
                        <label for="package">Package</label>
                        <select id="package" name="package" onchange="checkPackage()" required>
                            <optgroup label="Standard Packages">
                                <option value="Individual Package">Individual Package</option>
                                <option value="Business Package">Business Package</option>
                                <option value="Professional Package">Professional Package</option>
                                <option value="Custom Package">Custom Package (One-time)</option>
                            </optgroup>
                            <?php if (count($custom_packages) > 0): ?>
                                <optgroup label="Custom Packages">
                                    <?php foreach ($custom_packages as $pkg): ?>
                                        <option value="<?php echo htmlspecialchars($pkg['PackageName']); ?>">
                                            <?php echo htmlspecialchars($pkg['PackageName']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <small>Select a package or create custom</small>
                    </div>
                </div>

                <!-- Ready to Call Section -->
		<div class="form-section-title">Contact Readiness</div>
		<div class="checkbox-group">
		    <input type="checkbox" id="ready_to_call" name="ready_to_call" value="1" <?php echo $default_ready_to_call == 1 ? 'checked' : ''; ?>>
		    <label for="ready_to_call">Client is ready to be contacted</label>
		</div>

                <!-- Notes Section -->
                <div class="form-section-title">Additional Notes</div>
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Add any relevant notes about this client..."></textarea>
                </div>

                <div id="custom_programs_hidden_container"></div>

                <button type="submit" name="add_client" class="btn-submit">Add Client</button>
            </form>
        </div>
    </div>

    <!-- Other Software Modal -->
    <div id="other_software_modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Specify Other Software</h3>
            <input type="text" id="other_software" name="other_software" placeholder="Enter software name">
            <p class="red-bold">? Conversion Not Possible</p>
            <button type="button" onclick="submitOtherSoftware()">Submit</button>
        </div>
    </div>

    <!-- Custom Package Modal -->
    <div id="custom_package_modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Select Software Programs</h3>
            <p style="color: #666; font-size: 14px; margin-bottom: 15px;">This is a one-time custom package. To save custom packages for reuse, go to Settings.</p>
            <?php
            $software_options = [
                'prog_1040' => '1040 Program',
                'prog_Depreciation' => 'Depreciation Program',
                'prog_Proforma' => 'Proforma Program',
                'prog_1120' => '1120 Program',
                'prog_1120S' => '1120S Program',
                'prog_1065' => '1065 Program',
                'prog_1041' => '1041 Program',
                'prog_706Estate' => '706 Estate Program',
                'prog_709Gift' => '709 Gift Program',
                'prog_990Exempt' => '990 Exempt Program',
                'prog_DocArk' => 'Document Archive Program',
                'prog_1099Acc' => '1099 Accountant Program'
            ];
            foreach ($software_options as $key => $label): ?>
                <label>
                    <input type="checkbox" name="custom_programs[]" value="<?php echo $key; ?>">
                    <?php echo $label; ?>
                </label>
            <?php endforeach; ?>
            <button type="button" onclick="closeModal()" style="margin-top: 15px;">Save Selections</button>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>
