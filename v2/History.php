<?php
require_once "auth_check.php";
include 'db.php';

// Check if user is admin
$is_admin = ($_SESSION['role'] == 'admin');

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_history']) && $is_admin) {
    $history_id = intval($_POST['history_id']);
    $delete_sql = "DELETE FROM OnboardingHistory WHERE HistoryID = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $history_id);
    if ($delete_stmt->execute()) {
        $success_message = "History record deleted successfully!";
    } else {
        $error_message = "Error deleting record: " . $conn->error;
    }
    $delete_stmt->close();
}

// Handle inline editing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_history']) && $is_admin) {
    $history_id = intval($_POST['history_id']);
    $action_type = trim($_POST['action_type']);
    $action_details = trim($_POST['action_details']);
    $edited_by = intval($_POST['edited_by']);
    
    $update_sql = "UPDATE OnboardingHistory SET ActionType = ?, ActionDetails = ?, EditedBy = ? WHERE HistoryID = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssii", $action_type, $action_details, $edited_by, $history_id);
    if ($update_stmt->execute()) {
        $success_message = "History record updated successfully!";
    } else {
        $error_message = "Error updating record: " . $conn->error;
    }
    $update_stmt->close();
}

// Handle custom SQL execution
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['execute_sql']) && $is_admin) {
    $custom_sql = trim($_POST['custom_sql']);
    
    // Security check - only allow SELECT, UPDATE, DELETE for OnboardingHistory
    if (preg_match('/^(SELECT|UPDATE|DELETE)\s+/i', $custom_sql)) {
        try {
            if (stripos($custom_sql, 'SELECT') === 0) {
                $custom_result = $conn->query($custom_sql);
                $sql_executed = true;
            } else {
                $conn->query($custom_sql);
                $success_message = "SQL executed successfully! Rows affected: " . $conn->affected_rows;
            }
        } catch (Exception $e) {
            $error_message = "SQL Error: " . $e->getMessage();
        }
    } else {
        $error_message = "Only SELECT, UPDATE, and DELETE queries are allowed.";
    }
}

// Fetch all clients for dropdown
$clients_sql = "SELECT ClientID, ClientName FROM Onboarding ORDER BY ClientName";
$clients_result = $conn->query($clients_sql);
$clients = [];
while ($client = $clients_result->fetch_assoc()) {
    $clients[] = $client;
}

// Fetch all users for EditedBy dropdown
$users_sql = "SELECT UserID, FirstName, LastName FROM Users ORDER BY FirstName, LastName";
$users_result = $conn->query($users_sql);
$users = [];
while ($user = $users_result->fetch_assoc()) {
    $users[] = $user;
}

// Get selected client
$client_id = $_GET['client_id'] ?? ($_POST['client_id'] ?? '');

// Fetch history if client selected
$history_result = null;
if (!empty($client_id)) {
    if (isset($sql_executed) && $sql_executed && isset($custom_result)) {
        $history_result = $custom_result;
    } else {
        $history_sql = "SELECT h.*, u.FirstName, u.LastName 
                        FROM OnboardingHistory h 
                        LEFT JOIN Users u ON h.EditedBy = u.UserID 
                        WHERE h.ClientID = ? 
                        ORDER BY h.ActionTimestamp DESC";
        $stmt = $conn->prepare($history_sql);
        $stmt->bind_param("s", $client_id);
        $stmt->execute();
        $history_result = $stmt->get_result();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>History Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h2 {
            color: #333;
            border-bottom: 3px solid #007BFF;
            padding-bottom: 10px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .selector-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: #007BFF;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        
        th {
            background-color: #007BFF;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        
        tr:hover {
            background-color: #f5f5f5;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .sql-editor {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .sql-editor h3 {
            margin-top: 0;
            color: #007BFF;
            border-bottom: 2px solid #007BFF;
            padding-bottom: 10px;
        }
        
        textarea {
            width: 100%;
            min-height: 150px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            background-color: #f8f9fa;
            box-sizing: border-box;
        }
        
        .sql-query-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #007BFF;
            margin: 20px 0;
        }
        
        .sql-query-display pre {
            margin: 0;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 13px;
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
            background-color: #fefefe;
            margin: 5% auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 80%;
            max-width: 700px;
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
        
        .warning-banner {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .warning-banner strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>?? Client History Manager</h2>
        
        <?php if (isset($success_message)): ?>
            <div class="message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if ($is_admin): ?>
            <div class="warning-banner">
                <strong>?? Admin Mode Active</strong>
                You have full access to edit and delete history records. Use with caution!
            </div>
        <?php endif; ?>
        
        <!-- Client Selection -->
        <div class="selector-section">
            <h3>Select Client</h3>
            <form method="GET" action="">
                <div class="form-group">
                    <label for="client_id">Choose a Client:</label>
                    <select name="client_id" id="client_id" onchange="this.form.submit()">
                        <option value="">-- Select a Client --</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo htmlspecialchars($client['ClientID']); ?>" 
                                    <?php echo ($client_id == $client['ClientID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['ClientID'] . ' - ' . $client['ClientName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <?php if (!empty($client_id)): ?>
            
            <!-- SQL Query Display -->
            <div class="sql-query-display">
                <strong>Current Query:</strong>
                <pre>SELECT h.*, u.FirstName, u.LastName 
FROM OnboardingHistory h 
LEFT JOIN Users u ON h.EditedBy = u.UserID 
WHERE h.ClientID = '<?php echo htmlspecialchars($client_id); ?>' 
ORDER BY h.ActionTimestamp DESC;</pre>
            </div>
            
            <!-- Custom SQL Editor (Admin Only) -->
            <?php if ($is_admin): ?>
                <div class="sql-editor">
                    <h3>?? Custom SQL Query Editor</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                        <div class="form-group">
                            <label for="custom_sql">Enter Custom SQL (SELECT, UPDATE, DELETE only):</label>
                            <textarea name="custom_sql" id="custom_sql" placeholder="Example: SELECT * FROM OnboardingHistory WHERE ClientID = '<?php echo htmlspecialchars($client_id); ?>'"></textarea>
                        </div>
                        <button type="submit" name="execute_sql" class="btn btn-warning" onclick="return confirm('Are you sure you want to execute this SQL query?')">
                            ? Execute SQL
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- History Table -->
            <table>
                <thead>
                    <tr>
                        <th style="width: 80px;">History ID</th>
                        <th style="width: 120px;">Action Type</th>
                        <th>Action Details</th>
                        <th style="width: 180px;">Timestamp</th>
                        <th style="width: 150px;">Edited By</th>
                        <?php if ($is_admin): ?>
                            <th style="width: 150px;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history_result && $history_result->num_rows > 0): ?>
                        <?php while ($row = $history_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['HistoryID']); ?></td>
                                <td><?php echo htmlspecialchars($row['ActionType']); ?></td>
                                <td><?php echo htmlspecialchars($row['ActionDetails']); ?></td>
                                <td><?php echo htmlspecialchars($row['ActionTimestamp']); ?></td>
                                <td>
                                    <?php 
                                    if ($row['EditedBy']) {
                                        echo htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']);
                                        echo ' (' . $row['EditedBy'] . ')';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <?php if ($is_admin): ?>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-warning" onclick='openEditModal(<?php echo json_encode($row); ?>)'>?? Edit</button>
                                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this history record?');">
                                                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                                                <input type="hidden" name="history_id" value="<?php echo $row['HistoryID']; ?>">
                                                <button type="submit" name="delete_history" class="btn btn-danger">??? Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $is_admin ? '6' : '5'; ?>" style="text-align: center; padding: 40px;">
                                No history found for this client
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
        <?php else: ?>
            <div style="text-align: center; padding: 60px; background: white; border-radius: 8px;">
                <h3>?? Please select a client to view their history</h3>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Edit Modal -->
    <?php if ($is_admin): ?>
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEditModal()">&times;</span>
                <h3 style="color: #007BFF; margin-top: 0;">Edit History Record</h3>
                <form method="POST" action="">
                    <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                    <input type="hidden" id="edit_history_id" name="history_id">
                    
                    <div class="form-group">
                        <label for="edit_action_type">Action Type:</label>
                        <input type="text" id="edit_action_type" name="action_type" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_action_details">Action Details:</label>
                        <textarea id="edit_action_details" name="action_details" required style="min-height: 100px;"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_edited_by">Edited By (User ID):</label>
                        <select id="edit_edited_by" name="edited_by" required>
                            <option value="">-- Select User --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['UserID']; ?>">
                                    <?php echo htmlspecialchars($user['FirstName'] . ' ' . $user['LastName'] . ' (ID: ' . $user['UserID'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="update_history" class="btn btn-success">?? Save Changes</button>
                        <button type="button" class="btn btn-danger" onclick="closeEditModal()">? Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function openEditModal(row) {
                document.getElementById('edit_history_id').value = row.HistoryID;
                document.getElementById('edit_action_type').value = row.ActionType;
                document.getElementById('edit_action_details').value = row.ActionDetails;
                document.getElementById('edit_edited_by').value = row.EditedBy || '';
                document.getElementById('editModal').style.display = 'block';
            }
            
            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
            }
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('editModal');
                if (event.target == modal) {
                    closeEditModal();
                }
            }
        </script>
    <?php endif; ?>
</body>
</html>

<?php $conn->close(); ?>