<?php
require_once "auth_check.php"; // forces login check
include 'db.php';

$currentPage = 'remove';

// Check if the user is logged in and has a department of 1 (Sales) or is an admin
if (!isset($_SESSION['userid']) || 
    (!isset($_SESSION['department']) || $_SESSION['department'] != 1) && 
    (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')) {
    echo "Access denied.";
    exit;
}

$success_message = '';
$error_message = '';
$warning_message = '';

// Handle form submission for deleting clients
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_clients'])) {
    $clients_to_delete = $_POST['clients_to_delete'] ?? [];
    
    if (!empty($clients_to_delete)) {
        // Escape and quote ClientIDs properly for VARCHAR
        $clients_to_delete_escaped = array_map(function($id) use ($conn) {
            return "'" . $conn->real_escape_string($id) . "'";
        }, $clients_to_delete);

        $clients_to_delete_str = implode(',', $clients_to_delete_escaped);

        // Start a transaction
        $conn->begin_transaction();

        try {
            // Prepare statements to delete from all related tables
            $delete_onboarding_sql        = "DELETE FROM Onboarding WHERE ClientID IN ($clients_to_delete_str)";
            $delete_onboarding_details_sql= "DELETE FROM OnboardingDetails WHERE ClientID IN ($clients_to_delete_str)";
            $delete_onboarding_history_sql= "DELETE FROM OnboardingHistory WHERE ClientID IN ($clients_to_delete_str)";
            $delete_entitled_programs_sql = "DELETE FROM EntitledPrograms WHERE ClientID IN ($clients_to_delete_str)";
            $delete_notifications_sql     = "DELETE FROM Notification WHERE ClientID IN ($clients_to_delete_str)";

            // Execute deletions
            $conn->query($delete_onboarding_sql);
            $conn->query($delete_onboarding_details_sql);
            $conn->query($delete_onboarding_history_sql);
            $conn->query($delete_entitled_programs_sql);
            $conn->query($delete_notifications_sql);

            // Commit transaction
            $conn->commit();

            $success_message = count($clients_to_delete) . " client(s) deleted successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error: " . $e->getMessage();
        }
    } else {
        $warning_message = "No clients selected for deletion.";
    }
}

// Fetch all clients with additional details
$clients_sql = "SELECT o.ClientID, o.ClientName, o.SalesRep, o.PhoneNumber, o.Progress, 
                o.AssignedTech, u.FirstName, u.LastName,
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
                ORDER BY o.ClientID";
$clients_result = $conn->query($clients_sql);

// Count clients by status
$total_clients = 0;
$status_counts = [
    'Completed' => 0,
    'In Progress' => 0,
    'Not Started' => 0,
    'Stalled' => 0,
    'Canceled' => 0,
    'Pending New Version' => 0
];

$clients = [];
while ($client = $clients_result->fetch_assoc()) {
    $clients[] = $client;
    $total_clients++;
    if (isset($status_counts[$client['Status']])) {
        $status_counts[$client['Status']]++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Remove Clients</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <style>
        body {
            background-color: #f4f4f4;
        }

        .remove-container {
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

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 4px solid #007BFF;
        }

        .stat-card.total {
            border-left-color: #6c757d;
        }

        .stat-card.completed {
            border-left-color: #32CD32;
        }

        .stat-card.in-progress {
            border-left-color: #FFD700;
        }

        .stat-card.stalled {
            border-left-color: #FFA500;
        }

        .stat-card h4 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }

        /* Alert Messages */
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #28a745;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #dc3545;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .warning-message {
            background-color: #fff3cd;
            color: #856404;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #ffc107;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        /* Table Section */
        .table-section {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .table-section h3 {
            margin-top: 0;
            color: #dc3545;
            border-bottom: 2px solid #dc3545;
            padding-bottom: 10px;
            margin-bottom: 25px;
            font-size: 24px;
        }

        /* Search and Filter Controls */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #007BFF;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .table-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-select-all,
        .btn-deselect-all {
            padding: 10px 20px;
            border: 1px solid #007BFF;
            background-color: white;
            color: #007BFF;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-select-all:hover,
        .btn-deselect-all:hover {
            background-color: #007BFF;
            color: white;
        }

        .selected-count {
            padding: 10px 15px;
            background-color: #e3f2fd;
            color: #0d47a1;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        /* Table Styling */
        .clients-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .clients-table thead {
            background-color: #dc3545;
            color: white;
        }

        .clients-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            cursor: pointer;
            user-select: none;
        }

        .clients-table th:hover {
            background-color: #c82333;
        }

        .clients-table th.sortable::after {
            content: " ?";
            opacity: 0.5;
        }

        .clients-table th.sort-asc::after {
            content: " ?";
            opacity: 1;
        }

        .clients-table th.sort-desc::after {
            content: " ?";
            opacity: 1;
        }

        .clients-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        .clients-table tbody tr {
            transition: background-color 0.3s ease;
        }

        .clients-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .clients-table tbody tr.selected {
            background-color: #fff3cd;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-in-progress {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-not-started {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .status-stalled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-canceled {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .status-pending {
            background-color: #cfe2ff;
            color: #084298;
        }

        /* Checkbox Styling */
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Delete Button */
        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.2);
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-delete:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .no-clients {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .no-clients h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .no-clients p {
            font-size: 16px;
        }

        /* Warning Banner */
        .warning-banner {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
            margin-bottom: 20px;
        }

        .warning-banner h4 {
            margin: 0 0 10px 0;
            color: #856404;
            font-size: 18px;
        }

        .warning-banner p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .stats-overview {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            let selectedCount = 0;

            // Search functionality
            $('#searchInput').on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $('.clients-table tbody tr').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });

            // Update selected count
            function updateSelectedCount() {
                selectedCount = $('.clients-table tbody input[type="checkbox"]:checked').length;
                $('#selectedCount').text(selectedCount + ' selected');
                $('#deleteBtn').prop('disabled', selectedCount === 0);
            }

            // Individual checkbox change
            $('.clients-table tbody').on('change', 'input[type="checkbox"]', function() {
                $(this).closest('tr').toggleClass('selected', this.checked);
                updateSelectedCount();
            });

            // Select all
            $('#selectAllBtn').click(function() {
                $('.clients-table tbody tr:visible input[type="checkbox"]').prop('checked', true).trigger('change');
            });

            // Deselect all
            $('#deselectAllBtn').click(function() {
                $('.clients-table tbody input[type="checkbox"]').prop('checked', false).trigger('change');
            });

            // Sort table
            $('th.sortable').click(function() {
                var table = $(this).parents('table').eq(0);
                var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
                this.asc = !this.asc;
                if (!this.asc) {
                    rows = rows.reverse();
                }
                for (var i = 0; i < rows.length; i++) {
                    table.append(rows[i]);
                }
                
                // Update sort indicators
                $('th.sortable').removeClass('sort-asc sort-desc');
                $(this).addClass(this.asc ? 'sort-asc' : 'sort-desc');
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

            // Confirm deletion
            $('#deleteForm').on('submit', function(e) {
                if (selectedCount === 0) {
                    e.preventDefault();
                    return false;
                }
                
                var confirmMsg = 'Are you sure you want to delete ' + selectedCount + ' client(s)?\n\nThis action cannot be undone and will delete:\n- Client information\n- Onboarding details\n- History records\n- All related data';
                
                if (!confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    </script>
</head>
<body>
<?php include 'includes/header.php'; ?>
    <div class="remove-container">
        <h2>Remove Clients</h2>

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

        <?php if ($warning_message): ?>
            <div class="warning-message">
                ? <?php echo htmlspecialchars($warning_message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card total">
                <h4>Total Clients</h4>
                <div class="number"><?php echo $total_clients; ?></div>
            </div>
            <div class="stat-card completed">
                <h4>Completed</h4>
                <div class="number"><?php echo $status_counts['Completed']; ?></div>
            </div>
            <div class="stat-card in-progress">
                <h4>In Progress</h4>
                <div class="number"><?php echo $status_counts['In Progress']; ?></div>
            </div>
            <div class="stat-card">
                <h4>Not Started</h4>
                <div class="number"><?php echo $status_counts['Not Started']; ?></div>
            </div>
            <div class="stat-card stalled">
                <h4>Stalled</h4>
                <div class="number"><?php echo $status_counts['Stalled']; ?></div>
            </div>
        </div>

        <!-- Warning Banner -->
        <div class="warning-banner">
            <h4>? Caution: Permanent Action</h4>
            <p>Deleting clients will permanently remove all associated data including onboarding details, history records, and entitled programs. This action cannot be undone.</p>
        </div>

        <!-- Table Section -->
        <div class="table-section">
            <h3>Client Management</h3>

            <?php if ($total_clients > 0): ?>
                <form method="POST" action="" id="deleteForm">
                    <!-- Table Controls -->
                    <div class="table-controls">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="?? Search by client name, ID, sales rep...">
                        </div>
                        <div class="table-actions">
                            <span class="selected-count" id="selectedCount">0 selected</span>
                            <button type="button" id="selectAllBtn" class="btn-select-all">Select All</button>
                            <button type="button" id="deselectAllBtn" class="btn-deselect-all">Deselect All</button>
                        </div>
                    </div>

                    <!-- Clients Table -->
                    <table class="clients-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Select</th>
                                <th class="sortable">Client ID</th>
                                <th class="sortable">Client Name</th>
                                <th class="sortable">Assigned Tech</th>
                                <th class="sortable">Sales Rep</th>
                                <th class="sortable">Phone Number</th>
                                <th class="sortable">Progress</th>
                                <th class="sortable">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="clients_to_delete[]" value="<?php echo htmlspecialchars($client['ClientID']); ?>">
                                    </td>
                                    <td><?php echo htmlspecialchars($client['ClientID']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($client['ClientName']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($client['FirstName'] . ' ' . $client['LastName']); ?></td>
                                    <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                                    <td><?php echo htmlspecialchars($client['PhoneNumber']); ?></td>
                                    <td><?php echo round($client['Progress'], 1); ?>%</td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch($client['Status']) {
                                            case 'Completed':
                                                $status_class = 'status-completed';
                                                break;
                                            case 'In Progress':
                                                $status_class = 'status-in-progress';
                                                break;
                                            case 'Not Started':
                                                $status_class = 'status-not-started';
                                                break;
                                            case 'Stalled':
                                                $status_class = 'status-stalled';
                                                break;
                                            case 'Canceled':
                                                $status_class = 'status-canceled';
                                                break;
                                            case 'Pending New Version':
                                                $status_class = 'status-pending';
                                                break;
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($client['Status']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit" name="delete_clients" id="deleteBtn" class="btn-delete" disabled>
                        ??? Delete Selected Clients
                    </button>
                </form>
            <?php else: ?>
                <div class="no-clients">
                    <h3>No Clients Found</h3>
                    <p>There are currently no clients in the system to remove.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>