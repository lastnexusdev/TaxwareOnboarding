<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'users';

// Check if the user is logged in and is an admin
if (!isset($_SESSION['userid']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    echo "Access denied. Admin privileges required.";
    exit;
}

// Handle user actions
$message = '';
$error = '';

// Add new user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $department = intval($_POST['department']);
    $spanish = isset($_POST['spanish']) ? 1 : 0;
    
    // Check if username already exists
    $check_sql = "SELECT UserID FROM Users WHERE Username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('s', $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Username already exists. Please choose a different username.";
    } else {
        // Insert with default values for timecard fields
        $insert_sql = "INSERT INTO Users (Username, Password, FirstName, LastName, Email, Role, Department, Spanish, StartHour, EndHour) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '08:00', '17:00')";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param('ssssssii', $username, $password, $first_name, $last_name, $email, $role, $department, $spanish);
        
        if ($insert_stmt->execute()) {
            $message = "User added successfully!";
        } else {
            $error = "Error adding user: " . $conn->error;
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}

// Update user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = trim($_POST['edit_username']);
    $first_name = trim($_POST['edit_first_name']);
    $last_name = trim($_POST['edit_last_name']);
    $email = trim($_POST['edit_email']);
    $role = $_POST['edit_role'];
    $department = intval($_POST['edit_department']);
    $spanish = isset($_POST['edit_spanish']) ? 1 : 0;
    
    // Update password only if provided
    if (!empty($_POST['edit_password'])) {
        $password = password_hash($_POST['edit_password'], PASSWORD_DEFAULT);
        $update_sql = "UPDATE Users SET Username = ?, Password = ?, FirstName = ?, LastName = ?, Email = ?, Role = ?, Department = ?, Spanish = ? WHERE UserID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('ssssssiis', $username, $password, $first_name, $last_name, $email, $role, $department, $spanish, $user_id);
    } else {
        $update_sql = "UPDATE Users SET Username = ?, FirstName = ?, LastName = ?, Email = ?, Role = ?, Department = ?, Spanish = ? WHERE UserID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('sssssiis', $username, $first_name, $last_name, $email, $role, $department, $spanish, $user_id);
    }
    
    if ($update_stmt->execute()) {
        $message = "User updated successfully!";
    } else {
        $error = "Error updating user: " . $conn->error;
    }
    $update_stmt->close();
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['delete_user_id']);
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['userid']) {
        $error = "You cannot delete your own account!";
    } else {
        // Check if user has assigned clients
        $check_clients_sql = "SELECT COUNT(*) as client_count FROM Onboarding WHERE AssignedTech = ?";
        $check_stmt = $conn->prepare($check_clients_sql);
        $check_stmt->bind_param('i', $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['client_count'] > 0) {
            $error = "Cannot delete user with assigned clients. Please reassign clients first.";
        } else {
            $delete_sql = "DELETE FROM Users WHERE UserID = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $user_id);
            
            if ($delete_stmt->execute()) {
                $message = "User deleted successfully!";
            } else {
                $error = "Error deleting user: " . $conn->error;
            }
            $delete_stmt->close();
        }
        $check_stmt->close();
    }
}

// Fetch all users with assigned client counts
$users_sql = "SELECT u.*, 
              (SELECT COUNT(*) FROM Onboarding WHERE AssignedTech = u.UserID) as assigned_clients
              FROM Users u 
              ORDER BY u.Department, u.FirstName, u.LastName";
$users_result = $conn->query($users_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <style>
        .user-management-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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
        
        .add-user-section {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .add-user-section h3 {
            margin-top: 0;
            color: #007BFF;
            border-bottom: 2px solid #007BFF;
            padding-bottom: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group small {
            margin-top: 3px;
            color: #666;
            font-size: 12px;
        }
        
        .form-group input[type="checkbox"] {
            width: auto;
            margin-right: 5px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        
        .checkbox-group label {
            margin: 0;
            font-weight: normal;
        }
        
        .users-table-section {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .users-table-section h3 {
            margin-top: 0;
            color: #007BFF;
            border-bottom: 2px solid #007BFF;
            padding-bottom: 10px;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .users-table th {
            background-color: #007BFF;
            color: white;
            font-weight: bold;
        }
        
        .users-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-edit {
            background-color: #ffc107;
            color: #000;
        }
        
        .btn-edit:hover {
            background-color: #e0a800;
        }
        
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-delete:hover {
            background-color: #c82333;
        }
        
        .btn-primary {
            background-color: #007BFF;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
            padding: 10px 20px;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
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
        }
        
        .close:hover,
        .close:focus {
            color: #000;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-admin {
            background-color: #dc3545;
            color: white;
        }
        
        .badge-user {
            background-color: #28a745;
            color: white;
        }
        
        .badge-sales {
            background-color: #17a2b8;
            color: white;
        }
        
        .dept-1 {
            background-color: #e3f2fd;
        }
        
        .dept-2 {
            background-color: #fff3e0;
        }

        .password-strength {
            font-size: 12px;
            margin-top: 5px;
        }

        .password-weak { color: #dc3545; }
        .password-medium { color: #ffc107; }
        .password-strong { color: #28a745; }

        .client-count {
            background-color: #e7f3ff;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            color: #0066cc;
        }

        .stats-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            flex: 1;
            min-width: 200px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .stat-card h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            margin: 0;
        }

        .stat-card.sales {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-card.tech {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-card.spanish {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
    <div class="user-management-container">
        <h2>User Management</h2>
        
        <?php 
        // Calculate stats
        $total_users = 0;
        $sales_count = 0;
        $tech_count = 0;
        $spanish_count = 0;
        
        $stats_sql = "SELECT Department, Spanish, COUNT(*) as count FROM Users GROUP BY Department, Spanish";
        $stats_result = $conn->query($stats_sql);
        while ($stat = $stats_result->fetch_assoc()) {
            $total_users += $stat['count'];
            if ($stat['Department'] == 1) $sales_count += $stat['count'];
            if ($stat['Department'] == 2) $tech_count += $stat['count'];
            if ($stat['Spanish'] == 1) $spanish_count += $stat['count'];
        }
        ?>
        
        <div class="stats-summary">
            <div class="stat-card">
                <h4>Total Users</h4>
                <p class="number"><?php echo $total_users; ?></p>
            </div>
            <div class="stat-card sales">
                <h4>Sales Department</h4>
                <p class="number"><?php echo $sales_count; ?></p>
            </div>
            <div class="stat-card tech">
                <h4>Tech Department</h4>
                <p class="number"><?php echo $tech_count; ?></p>
            </div>
            <div class="stat-card spanish">
                <h4>Spanish Speakers</h4>
                <p class="number"><?php echo $spanish_count; ?></p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Add User Section -->
        <div class="add-user-section">
            <h3>Add New User</h3>
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required minlength="3">
                        <small>Minimum 3 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="6" onkeyup="checkPasswordStrength('password', 'password-strength')">
                        <span id="password-strength" class="password-strength"></span>
                    </div>
                    
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role *</label>
                        <select id="role" name="role" required>
                            <option value="user">User</option>
                            <option value="sales">Sales</option>
                            <option value="admin">Admin</option>
                        </select>
                        <small>Admin: Full access | Sales: Add/remove clients | User: View only</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department *</label>
                        <select id="department" name="department" required>
                            <option value="1">Sales (1)</option>
                            <option value="2">Tech (2)</option>
                        </select>
                        <small>Sales: Client acquisition | Tech: Onboarding & support</small>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="spanish" name="spanish" value="1">
                    <label for="spanish">Spanish Speaking (for client assignment)</label>
                </div>
                
                <button type="submit" name="add_user" class="btn btn-primary" style="margin-top: 20px;">Add User</button>
            </form>
        </div>
        
        <!-- Users Table Section -->
        <div class="users-table-section">
            <h3>Existing Users</h3>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Spanish</th>
                        <th>Assigned Clients</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($user = $users_result->fetch_assoc()): ?>
                        <tr class="<?php echo $user['Department'] == 1 ? 'dept-1' : 'dept-2'; ?>">
                            <td><?php echo htmlspecialchars($user['UserID']); ?></td>
                            <td><?php echo htmlspecialchars($user['Username']); ?></td>
                            <td><?php echo htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']); ?></td>
                            <td><?php echo htmlspecialchars($user['Email']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['Role'] == 'admin' ? 'badge-admin' : ($user['Role'] == 'sales' ? 'badge-sales' : 'badge-user'); ?>">
                                    <?php echo strtoupper($user['Role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    echo $user['Department'] == 1 ? 'Sales' : 'Tech';
                                    echo ' (' . $user['Department'] . ')';
                                ?>
                            </td>
                            <td><?php echo $user['Spanish'] ? '✓' : '✗'; ?></td>
                            <td>
                                <?php if ($user['assigned_clients'] > 0): ?>
                                    <span class="client-count"><?php echo $user['assigned_clients']; ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-edit" onclick='openEditModal(<?php echo json_encode($user); ?>)'>Edit</button>
                                    <?php if ($user['UserID'] != $_SESSION['userid']): ?>
                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?<?php echo $user['assigned_clients'] > 0 ? ' This user has ' . $user['assigned_clients'] . ' assigned clients.' : ''; ?>');">
                                            <input type="hidden" name="delete_user_id" value="<?php echo $user['UserID']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-delete">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h3>Edit User</h3>
            <form method="POST" action="" onsubmit="return validateEditForm()">
                <input type="hidden" id="edit_user_id" name="user_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="edit_username" required minlength="3">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_password">Password</label>
                        <input type="password" id="edit_password" name="edit_password" minlength="6" onkeyup="checkPasswordStrength('edit_password', 'edit-password-strength')">
                        <small>Leave blank to keep current password</small>
                        <span id="edit-password-strength" class="password-strength"></span>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_first_name">First Name *</label>
                        <input type="text" id="edit_first_name" name="edit_first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_last_name">Last Name *</label>
                        <input type="text" id="edit_last_name" name="edit_last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" id="edit_email" name="edit_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_role">Role *</label>
                        <select id="edit_role" name="edit_role" required>
                            <option value="user">User</option>
                            <option value="sales">Sales</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_department">Department *</label>
                        <select id="edit_department" name="edit_department" required>
                            <option value="1">Sales (1)</option>
                            <option value="2">Tech (2)</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="edit_spanish" name="edit_spanish" value="1">
                    <label for="edit_spanish">Spanish Speaking</label>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function checkPasswordStrength(inputId, strengthId) {
            const password = document.getElementById(inputId).value;
            const strengthSpan = document.getElementById(strengthId);
            
            if (password.length === 0) {
                strengthSpan.textContent = '';
                return;
            }
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            if (strength <= 2) {
                strengthSpan.textContent = 'Weak password';
                strengthSpan.className = 'password-strength password-weak';
            } else if (strength <= 4) {
                strengthSpan.textContent = 'Medium password';
                strengthSpan.className = 'password-strength password-medium';
            } else {
                strengthSpan.textContent = 'Strong password';
                strengthSpan.className = 'password-strength password-strong';
            }
        }

        function validateAddForm() {
            const password = document.getElementById('password').value;
            if (password.length < 6) {
                alert('Password must be at least 6 characters long');
                return false;
            }
            return true;
        }

        function validateEditForm() {
            const password = document.getElementById('edit_password').value;
            if (password !== '' && password.length < 6) {
                alert('Password must be at least 6 characters long');
                return false;
            }
            return true;
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.UserID;
            document.getElementById('edit_username').value = user.Username;
            document.getElementById('edit_first_name').value = user.FirstName;
            document.getElementById('edit_last_name').value = user.LastName;
            document.getElementById('edit_email').value = user.Email;
            document.getElementById('edit_role').value = user.Role;
            document.getElementById('edit_department').value = user.Department;
            document.getElementById('edit_spanish').checked = user.Spanish == 1;
            
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit-password-strength').textContent = '';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>