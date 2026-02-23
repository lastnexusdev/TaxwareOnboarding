<?php
session_start();
$requireRoles = ['admin', 'sales'];
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'edit';

// Check access
if (!isset($_SESSION['userid'])) {
    echo "Access denied: not logged in properly.";
    exit;
}

$success_message = '';
$error_message = '';

// Fetch techs for assignment dropdown
$techs = [];
$techs_sql = "SELECT UserID, FirstName, LastName, Spanish FROM Users WHERE Department = 2 ORDER BY FirstName, LastName";
$techs_result = $conn->query($techs_sql);
if ($techs_result) {
    while ($row = $techs_result->fetch_assoc()) {
        $techs[] = $row;
    }
}

// Fetch all software programs for entitled programs
$programs_sql = "SHOW COLUMNS FROM EntitledPrograms LIKE 'prog_%'";
$programs_result = $conn->query($programs_sql);
$available_programs = [];
while ($row = $programs_result->fetch_assoc()) {
    $program_key = $row['Field'];
    $program_name = str_replace(['prog_', '_'], ['', ' '], $program_key);
    $available_programs[$program_key] = $program_name;
}

// Handle client update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client'])) {
    $client_id = $_POST['client_id'] ?? '';
    $original_client_id = $_POST['original_client_id'] ?? '';
    $client_name = $_POST['client_name'] ?? '';
    $assigned_tech = $_POST['assigned_tech'] ?? '';
    $sales_rep = $_POST['sales_rep'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $previous_software = $_POST['previous_software'] ?? '';
    $conversion_needed = $_POST['conversion_needed'] ?? 'No';
    $spanish = $_POST['spanish'] ?? 'No';
    $bank_enrollment = $_POST['bank_enrollment'] ?? 'No';
    $package = trim($_POST['package'] ?? ''); // Free text input
    $ready_to_call = isset($_POST['ready_to_call']) ? 1 : 0;
    $stalled = isset($_POST['stalled']) ? 1 : 0;
    $cancelled = isset($_POST['cancelled']) ? 1 : 0;

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if ClientID changed
        if ($client_id !== $original_client_id) {
            $check_sql = "SELECT ClientID FROM Onboarding WHERE ClientID = ? AND ClientID != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ss', $client_id, $original_client_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception("Client ID already exists. Please choose a different ID.");
            }
            $check_stmt->close();
        }

        // Update main client information
        $update_sql = "UPDATE Onboarding SET 
            ClientID = ?,
            ClientName = ?,
            AssignedTech = ?,
            SalesRep = ?,
            Email = ?,
            PhoneNumber = ?,
            PreviousSoftware = ?,
            ConvertionNeeded = ?,
            Spanish = ?,
            BankEnrollment = ?,
            Package = ?,
            ReadyToCall = ?,
            Stalled = ?,
            Cancelled = ?
            WHERE ClientID = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param(
            'sssssssssssiiis',
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
            $stalled,
            $cancelled,
            $original_client_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating client: " . $update_stmt->error);
        }
        $update_stmt->close();

        // Handle entitled programs - manually selected from checkboxes
        $entitled_programs = [];
        
        // Initialize all programs to 0
        foreach ($available_programs as $prog_key => $prog_name) {
            $entitled_programs[$prog_key] = 0;
        }
        
        // Set checked programs to 1
        if (isset($_POST['entitled_programs']) && is_array($_POST['entitled_programs'])) {
            foreach ($_POST['entitled_programs'] as $prog_key) {
                if (isset($entitled_programs[$prog_key])) {
                    $entitled_programs[$prog_key] = 1;
                }
            }
        }

        // Check if entitled programs record exists
        $check_ent_sql = "SELECT ClientID FROM EntitledPrograms WHERE ClientID = ?";
        $check_ent_stmt = $conn->prepare($check_ent_sql);
        $check_ent_stmt->bind_param('s', $client_id);
        $check_ent_stmt->execute();
        $ent_exists = $check_ent_stmt->get_result()->num_rows > 0;
        $check_ent_stmt->close();

        if ($ent_exists) {
            // Update existing record
            $update_ent_parts = [];
            $update_ent_types = '';
            $update_ent_values = [];
            
            foreach ($entitled_programs as $prog_key => $prog_value) {
                $update_ent_parts[] = "$prog_key = ?";
                $update_ent_types .= 'i';
                $update_ent_values[] = $prog_value;
            }
            
            $update_ent_sql = "UPDATE EntitledPrograms SET " . implode(', ', $update_ent_parts) . " WHERE ClientID = ?";
            $update_ent_types .= 's';
            $update_ent_values[] = $client_id;
            
            $update_ent_stmt = $conn->prepare($update_ent_sql);
            $update_ent_stmt->bind_param($update_ent_types, ...$update_ent_values);
            $update_ent_stmt->execute();
            $update_ent_stmt->close();
        } else {
            // Insert new record
            $columns = array_keys($entitled_programs);
            $placeholders = array_fill(0, count($columns), '?');
            
            $insert_ent_sql = "INSERT INTO EntitledPrograms (ClientID, " . implode(', ', $columns) . ") VALUES (?, " . implode(', ', $placeholders) . ")";
            $insert_ent_stmt = $conn->prepare($insert_ent_sql);
            
            $types = 's' . str_repeat('i', count($entitled_programs));
            $values = array_merge([$client_id], array_values($entitled_programs));
            
            $insert_ent_stmt->bind_param($types, ...$values);
            $insert_ent_stmt->execute();
            $insert_ent_stmt->close();
        }

        // If ClientID changed, update related tables
        if ($client_id !== $original_client_id) {
            $update_details_sql = "UPDATE OnboardingDetails SET ClientID = ? WHERE ClientID = ?";
            $update_details_stmt = $conn->prepare($update_details_sql);
            $update_details_stmt->bind_param('ss', $client_id, $original_client_id);
            $update_details_stmt->execute();
            $update_details_stmt->close();

            $update_history_sql = "UPDATE OnboardingHistory SET ClientID = ? WHERE ClientID = ?";
            $update_history_stmt = $conn->prepare($update_history_sql);
            $update_history_stmt->bind_param('ss', $client_id, $original_client_id);
            $update_history_stmt->execute();
            $update_history_stmt->close();

            $update_notif_sql = "UPDATE Notification SET ClientID = ? WHERE ClientID = ?";
            $update_notif_stmt = $conn->prepare($update_notif_sql);
            $update_notif_stmt->bind_param('ss', $client_id, $original_client_id);
            $update_notif_stmt->execute();
            $update_notif_stmt->close();
        }

        // Log the change
        $action_details = "Client information updated by " . $_SESSION['username'];
        $history_sql = "INSERT INTO OnboardingHistory (ClientID, ActionType, ActionDetails, EditedBy) VALUES (?, 'Client Updated', ?, ?)";
        $history_stmt = $conn->prepare($history_sql);
        $history_stmt->bind_param('ssi', $client_id, $action_details, $_SESSION['userid']);
        $history_stmt->execute();
        $history_stmt->close();

        $conn->commit();
        $success_message = "Client '{$client_name}' updated successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}

// Fetch all clients
$clients_sql = "SELECT o.*, u.FirstName, u.LastName,
                CASE
                    WHEN o.Cancelled = 1 THEN 'Canceled'
                    WHEN o.Progress = 100 THEN 'Completed'
                    WHEN o.CompletedUntilNewVersion = 1 THEN 'Pending New Version'
                    WHEN o.Stalled = 1 THEN 'Stalled'
                    WHEN o.Progress = 0 THEN 'Not Started'
                    WHEN o.Progress > 0 AND o.Progress < 100 THEN 'In Progress'
                    ELSE 'Unknown'
                END AS Status
                FROM Onboarding o
                LEFT JOIN Users u ON o.AssignedTech = u.UserID
                ORDER BY o.ClientName";
$clients_result = $conn->query($clients_sql);
$clients = [];
if ($clients_result) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Clients</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background-color: #f4f4f4; }
        .edit-container { max-width: 1600px; margin: 0 auto; padding: 20px; }
        h2 { text-align: center; color: #333; margin-bottom: 30px; font-size: 32px; font-weight: 700; }
        .success-message { background-color: #d4edda; color: #155724; padding: 15px 20px; border-radius: 6px; border-left: 4px solid #28a745; margin-bottom: 20px; }
        .error-message { background-color: #f8d7da; color: #721c24; padding: 15px 20px; border-radius: 6px; border-left: 4px solid #dc3545; margin-bottom: 20px; }
        .table-section { background-color: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); }
        .table-section h3 { margin-top: 0; color: #007BFF; border-bottom: 2px solid #007BFF; padding-bottom: 10px; margin-bottom: 25px; font-size: 24px; }
        .table-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .search-box { flex: 1; min-width: 250px; }
        .search-box input { width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .search-box input:focus { outline: none; border-color: #007BFF; box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1); }
        .clients-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .clients-table thead { background-color: #007BFF; color: white; }
        .clients-table th { padding: 12px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 12px; cursor: pointer; user-select: none; }
        .clients-table th:hover { background-color: #0056b3; }
        .clients-table th.sortable::after { content: " ?"; opacity: 0.5; }
        .clients-table th.sort-asc::after { content: " ?"; opacity: 1; }
        .clients-table th.sort-desc::after { content: " ?"; opacity: 1; }
        .clients-table td { padding: 12px; border-bottom: 1px solid #ddd; }
        .clients-table tbody tr { transition: background-color 0.3s ease; }
        .clients-table tbody tr:hover { background-color: #f8f9fa; }
        .status-badge { padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-in-progress { background-color: #fff3cd; color: #856404; }
        .status-not-started { background-color: #d1ecf1; color: #0c5460; }
        .status-stalled { background-color: #f8d7da; color: #721c24; }
        .status-canceled { background-color: #e2e3e5; color: #383d41; }
        .status-pending { background-color: #cfe2ff; color: #084298; }
        .btn-edit { background-color: #ffc107; color: #000; padding: 8px 16px; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; transition: all 0.3s ease; }
        .btn-edit:hover { background-color: #e0a800; transform: translateY(-2px); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.5); }
        .modal-content { background-color: #fff; margin: 2% auto; padding: 0; border-radius: 12px; width: 95%; max-width: 1200px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3); }
        .modal-header { background: linear-gradient(135deg, #007BFF 0%, #0056b3 100%); color: white; padding: 20px 30px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 24px; }
        .close { color: white; font-size: 32px; font-weight: bold; cursor: pointer; line-height: 20px; transition: color 0.3s; }
        .close:hover { color: #f8f9fa; }
        .modal-body { padding: 30px; }
        .form-section-title { font-size: 18px; font-weight: 600; color: #555; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 1px solid #ddd; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 8px; color: #333; font-size: 14px; }
        .form-group input[type="text"], .form-group input[type="email"], .form-group select { padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; transition: border-color 0.3s ease, box-shadow 0.3s ease; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #007BFF; box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1); }
        .form-group small { margin-top: 5px; color: #666; font-size: 12px; }
        .checkbox-group { display: flex; align-items: center; padding: 15px; background-color: #f8f9fa; border-radius: 6px; margin-bottom: 15px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; margin-right: 10px; cursor: pointer; }
        .checkbox-group label { margin: 0; font-weight: normal; cursor: pointer; }
        .checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 15px; max-height: 300px; overflow-y: auto; padding: 15px; background-color: #f8f9fa; border-radius: 6px; }
        .checkbox-item { display: flex; align-items: center; padding: 8px; background-color: white; border-radius: 4px; cursor: pointer; transition: background-color 0.3s ease; }
        .checkbox-item:hover { background-color: #e9ecef; }
        .checkbox-item input[type="checkbox"] { margin-right: 8px; width: 18px; height: 18px; cursor: pointer; }
        .checkbox-item label { margin: 0; cursor: pointer; font-size: 14px; }
        .modal-footer { padding: 20px 30px; background-color: #f8f9fa; border-radius: 0 0 12px 12px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-primary { background: linear-gradient(135deg, #007BFF 0%, #0056b3 100%); color: white; padding: 12px 24px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3); }
        .btn-secondary { background-color: #6c757d; color: white; padding: 12px 24px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
        .btn-secondary:hover { background-color: #5a6268; }
        .no-clients { text-align: center; padding: 60px 20px; color: #999; }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
    <div class="edit-container">
        <h2>Edit Clients</h2>

        <?php if ($success_message): ?>
            <div class="success-message">? <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="error-message">? <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="table-section">
            <h3>All Clients (<?php echo count($clients); ?>)</h3>

            <?php if (count($clients) > 0): ?>
                <div class="table-controls">
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="?? Search by client name, ID, tech, sales rep...">
                    </div>
                </div>

                <table class="clients-table">
                    <thead>
                        <tr>
                            <th class="sortable">Client ID</th>
                            <th class="sortable">Client Name</th>
                            <th class="sortable">Assigned Tech</th>
                            <th class="sortable">Sales Rep</th>
                            <th class="sortable">Phone</th>
                            <th class="sortable">Progress</th>
                            <th class="sortable">Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($client['ClientID']); ?></strong></td>
                                <td><?php echo htmlspecialchars($client['ClientName']); ?></td>
                                <td><?php echo htmlspecialchars($client['FirstName'] . ' ' . $client['LastName']); ?></td>
                                <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                                <td><?php echo htmlspecialchars($client['PhoneNumber']); ?></td>
                                <td><?php echo round($client['Progress'], 1); ?>%</td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch($client['Status']) {
                                        case 'Completed': $status_class = 'status-completed'; break;
                                        case 'In Progress': $status_class = 'status-in-progress'; break;
                                        case 'Not Started': $status_class = 'status-not-started'; break;
                                        case 'Stalled': $status_class = 'status-stalled'; break;
                                        case 'Canceled': $status_class = 'status-canceled'; break;
                                        case 'Pending New Version': $status_class = 'status-pending'; break;
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($client['Status']); ?></span>
                                </td>
                                <td>
                                    <button class="btn-edit" onclick='openEditModal(<?php echo json_encode($client); ?>)'>Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-clients">
                    <h3>No Clients Found</h3>
                    <p>There are currently no clients in the system.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<!-- Edit Client Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Client</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            
            <form method="POST" action="" id="editClientForm">
                <div class="modal-body">
                    <input type="hidden" id="original_client_id" name="original_client_id">
                    
                    <div class="form-section-title">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="client_id">Client ID *</label>
                            <input type="text" id="client_id" name="client_id" required>
                            <small>Unique identifier for the client</small>
                        </div>
                        <div class="form-group">
                            <label for="client_name">Client Name *</label>
                            <input type="text" id="client_name" name="client_name" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Phone Number *</label>
                            <input type="text" id="phone_number" name="phone_number" required>
                        </div>
                        <div class="form-group">
                            <label for="sales_rep">Sales Rep *</label>
                            <input type="text" id="sales_rep" name="sales_rep" required>
                        </div>
                    </div>

                    <div class="form-section-title">Tech Assignment</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="spanish">Spanish Required</label>
                            <select id="spanish" name="spanish" onchange="updateTechOptions()" required>
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="assigned_tech">Assigned Tech *</label>
                            <select id="assigned_tech" name="assigned_tech" required>
                                <?php foreach ($techs as $tech): ?>
                                    <option value="<?php echo htmlspecialchars($tech['UserID']); ?>" data-spanish="<?php echo $tech['Spanish']; ?>">
                                        <?php echo htmlspecialchars($tech['FirstName'] . ' ' . $tech['LastName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">Software & Conversion</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="previous_software">Previous Software *</label>
                            <select id="previous_software" name="previous_software" required>
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
                            <select id="conversion_needed" name="conversion_needed" required>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="bank_enrollment">Bank Enrollment</label>
                            <select id="bank_enrollment" name="bank_enrollment" required>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="package">Package Name *</label>
                            <input type="text" id="package" name="package" placeholder="Enter package name" required>
                            <small>Type any package name (e.g., Individual Package, Business Package, Custom Package)</small>
                        </div>
                    </div>

                    <div class="form-section-title">Status Settings</div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="ready_to_call" name="ready_to_call" value="1">
                        <label for="ready_to_call">Client is ready to be contacted</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="stalled" name="stalled" value="1">
                        <label for="stalled">Mark as Stalled</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="cancelled" name="cancelled" value="1">
                        <label for="cancelled">Mark as Cancelled</label>
                    </div>

                    <div class="form-section-title">Entitled Programs</div>
                    <small style="color: #666; margin-bottom: 10px; display: block;">
                        Select which software programs this client is entitled to use. These selections are independent of the package name above.
                    </small>
                    <div class="checkbox-grid" id="entitled_programs_grid">
                        <?php foreach ($available_programs as $prog_key => $prog_name): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="prog_<?php echo $prog_key; ?>" name="entitled_programs[]" value="<?php echo $prog_key; ?>">
                                <label for="prog_<?php echo $prog_key; ?>"><?php echo htmlspecialchars($prog_name); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="update_client" class="btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
<script>
        // Store data for JavaScript
        var techsData = <?php echo json_encode($techs); ?>;

        // Search functionality
        $(document).ready(function() {
            $('#searchInput').on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $('.clients-table tbody tr').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });

            // Table sorting
            $('th.sortable').click(function() {
                var table = $(this).parents('table').eq(0);
                var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
                this.asc = !this.asc;
                if (!this.asc) { rows = rows.reverse(); }
                for (var i = 0; i < rows.length; i++) { table.append(rows[i]); }
                $('th.sortable').removeClass('sort-asc sort-desc');
                $(this).addClass(this.asc ? 'sort-asc' : 'sort-desc');
            });
        });

        function comparer(index) {
            return function(a, b) {
                var valA = getCellValue(a, index);
                var valB = getCellValue(b, index);
                return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.localeCompare(valB);
            }
        }

        function getCellValue(row, index) {
            return $(row).children('td').eq(index).text();
        }

        function openEditModal(client) {
            console.log('Opening modal for client:', client.ClientID);
            
            // Set basic information
            document.getElementById('original_client_id').value = client.ClientID;
            document.getElementById('client_id').value = client.ClientID;
            document.getElementById('client_name').value = client.ClientName;
            document.getElementById('email').value = client.Email;
            document.getElementById('phone_number').value = client.PhoneNumber;
            document.getElementById('sales_rep').value = client.SalesRep;
            
            // Set dropdowns
            document.getElementById('spanish').value = client.Spanish;
            document.getElementById('assigned_tech').value = client.AssignedTech;
            document.getElementById('previous_software').value = client.PreviousSoftware;
            document.getElementById('conversion_needed').value = client.ConvertionNeeded;
            document.getElementById('bank_enrollment').value = client.BankEnrollment;
            
            // Set package as text input
            document.getElementById('package').value = client.Package || '';
            
            // Set checkboxes
            document.getElementById('ready_to_call').checked = client.ReadyToCall == 1;
            document.getElementById('stalled').checked = client.Stalled == 1;
            document.getElementById('cancelled').checked = client.Cancelled == 1;
            
            // Update tech options based on Spanish requirement
            updateTechOptions();
            
            // Fetch and set entitled programs
            fetchEntitledPrograms(client.ClientID);
            
            // Show modal
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function updateTechOptions() {
            var spanishRequired = document.getElementById('spanish').value;
            var assignedTechSelect = document.getElementById('assigned_tech');
            var currentValue = assignedTechSelect.value;
            
            assignedTechSelect.innerHTML = '';
            
            var techsFiltered = techsData.filter(function(tech) {
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
                assignedTechSelect.appendChild(option);
            }
            
            if (currentValue) {
                assignedTechSelect.value = currentValue;
            }
        }

        function fetchEntitledPrograms(clientId) {
            // Uncheck all programs first
            document.querySelectorAll('#entitled_programs_grid input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = false;
            });
            
            // Fetch entitled programs via AJAX
            $.ajax({
                url: 'fetch_entitled_programs.php',
                type: 'POST',
                data: { client_id: clientId },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.programs) {
                        for (var progKey in response.programs) {
                            if (response.programs[progKey] == 1) {
                                var checkbox = document.getElementById('prog_' + progKey);
                                if (checkbox) { 
                                    checkbox.checked = true; 
                                }
                            }
                        }
                    }
                },
                error: function() {
                    console.error('Failed to fetch entitled programs');
                }
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('editModal');
            if (event.target == modal) { 
                closeModal(); 
            }
        }

        // Phone number formatting
        var phoneNumberInput = document.getElementById('phone_number');
        if (phoneNumberInput) {
            phoneNumberInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                let formatted = '';
                if (value.length > 0) { 
                    formatted = '(' + value.substring(0, 3); 
                }
                if (value.length >= 4) { 
                    formatted += ') ' + value.substring(3, 6); 
                }
                if (value.length >= 7) { 
                    formatted += '-' + value.substring(6, 10); 
                }
                e.target.value = formatted;
            });
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>
