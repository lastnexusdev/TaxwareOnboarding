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